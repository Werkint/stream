<?php

namespace React\Stream;

use Evenement\EventEmitter;
use React\EventLoop\LoopInterface;

/** @event full-drain */
class Buffer extends EventEmitter implements WritableStreamInterface
{
    /**
     * TODO: remove after @link https://bugs.php.net/bug.php?id=72333 is fixed
     */
    const SLL_CHUNK_LIMIT = 8192;

    public $stream;
    public $listening = false;
    public $softLimit = 65536;
    public $chunkLimit = null;
    private $writable = true;
    private $loop;
    private $data = '';

    public function __construct($stream, LoopInterface $loop)
    {
        if (!is_resource($stream) || get_resource_type($stream) !== "stream") {
            throw new \InvalidArgumentException('First parameter must be a valid stream resource');
        }

        $this->stream = $stream;
        $this->loop = $loop;

        $meta = stream_get_meta_data($stream);
        if (isset($meta['blocked']) && !$meta['blocked']) {
            if (isset($meta['stream_type']) && $meta['stream_type'] === 'tcp_socket/ssl') {
                $this->chunkLimit = self::SLL_CHUNK_LIMIT;
            }
        }
    }

    public function isWritable()
    {
        return $this->writable;
    }

    public function write($data)
    {
        if (!$this->writable) {
            return;
        }

        $this->data .= $data;

        if (!$this->listening && $this->data !== '') {
            $this->listening = true;

            $this->loop->addWriteStream($this->stream, array($this, 'handleWrite'));
        }

        return !isset($this->data[$this->softLimit - 1]);
    }

    public function end($data = null)
    {
        if (null !== $data) {
            $this->write($data);
        }

        $this->writable = false;

        if ($this->listening) {
            $this->on('full-drain', array($this, 'close'));
        } else {
            $this->close();
        }
    }

    public function close()
    {
        $this->writable = false;
        $this->listening = false;
        $this->data = '';

        $this->emit('close', array($this));
    }

    public function handleWrite()
    {
        $error = null;
        set_error_handler(function ($errno, $errstr, $errfile, $errline) use (&$error) {
            $error = array(
                'message' => $errstr,
                'number' => $errno,
                'file' => $errfile,
                'line' => $errline
            );
        });

        $sent = fwrite(
            $this->stream,
            $this->chunkLimit ? substr($this->data, 0, $this->chunkLimit) : $this->data
        );

        restore_error_handler();

        // Only report errors if *nothing* could be sent.
        // Any hard (permanent) error will fail to send any data at all.
        // Sending excessive amounts of data will only flush *some* data and then
        // report a temporary error (EAGAIN) which we do not raise here in order
        // to keep the stream open for further tries to write.
        // Should this turn out to be a permanent error later, it will eventually
        // send *nothing* and we can detect this.
        if ($sent === 0 || $sent === false) {
            if ($error === null) {
                $error = new \RuntimeException('Send failed');
            } else {
                $error = new \ErrorException(
                    $error['message'],
                    0,
                    $error['number'],
                    $error['file'],
                    $error['line']
                );
            }

            $this->emit('error', array(new \RuntimeException('Unable to write to stream: ' . $error->getMessage(), 0, $error), $this));

            return;
        }

        $exceeded = isset($this->data[$this->softLimit - 1]);
        $this->data = (string) substr($this->data, $sent);

        // buffer has been above limit and is now below limit
        if ($exceeded && !isset($this->data[$this->softLimit - 1])) {
            $this->emit('drain', array($this));
        }

        // buffer is now completely empty (and not closed already)
        if ($this->data === '' && $this->listening) {
            $this->loop->removeWriteStream($this->stream);
            $this->listening = false;

            $this->emit('full-drain', array($this));
        }
    }
}
