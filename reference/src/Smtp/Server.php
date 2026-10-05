<?php

declare(strict_types=1);

namespace Msgpit\Smtp;

use Closure;

/**
 * A small SMTP listener. Single process, many connections: PHP here has no pcntl, so instead of
 * forking it selects over the open sockets. That also avoids one slow client blocking the rest.
 */
final class Server
{
    private const READ_CHUNK = 65_536;

    /** @param Closure(Envelope): void $onMessage */
    public function __construct(
        private readonly string $address,
        private readonly Closure $onMessage,
        private readonly string $hostname = 'msgpit',
        private readonly int $idleTimeout = 300,
    ) {}

    public function run(?int $stopAfterSeconds = null): void
    {
        $listener = stream_socket_server($this->address, $errno, $error);

        if ($listener === false) {
            throw new \RuntimeException("Cannot listen on {$this->address}: {$error} ({$errno})");
        }

        stream_set_blocking($listener, false);


        /** @var array<int, Connection> $connections */
        $connections = [];
        $deadline = $stopAfterSeconds === null ? null : time() + $stopAfterSeconds;

        while ($deadline === null || time() < $deadline) {
            $read = [$listener];

            foreach ($connections as $connection) {
                $read[] = $connection->socket;
            }

            $write = null;
            $except = null;

            if (@stream_select($read, $write, $except, 1) === false) {
                continue;
            }

            foreach ($read as $ready) {
                if ($ready === $listener) {
                    $this->accept($listener, $connections);

                    continue;
                }

                $key = (int) $ready;

                if (isset($connections[$key]) && !$this->serve($connections[$key])) {
                    fclose($connections[$key]->socket);
                    unset($connections[$key]);
                }
            }

            $this->dropIdle($connections);
        }

        foreach ($connections as $connection) {
            fclose($connection->socket);
        }

        fclose($listener);
    }

    /**
     * @param resource $listener
     * @param array<int, Connection> $connections
     */
    private function accept($listener, array &$connections): void
    {
        $socket = @stream_socket_accept($listener, 0);

        if ($socket === false) {
            return;
        }

        stream_set_blocking($socket, false);

        $session = new Session($this->hostname);
        $connection = new Connection($socket, $session);
        $connections[(int) $socket] = $connection;

        $this->write($connection, [$session->greeting()]);
    }

    /** @return bool Whether the connection stays open. */
    private function serve(Connection $connection): bool
    {
        $chunk = @fread($connection->socket, self::READ_CHUNK);

        if ($chunk === false || $chunk === '') {
            return !feof($connection->socket);
        }

        $connection->touch();
        $connection->buffer .= $chunk;

        while (($position = strpos($connection->buffer, "\n")) !== false) {
            $line = substr($connection->buffer, 0, $position + 1);
            $connection->buffer = substr($connection->buffer, $position + 1);

            $close = $connection->session->shouldClose($line);
            $this->write($connection, $connection->session->line($line));

            foreach ($connection->session->takeDelivered() as $envelope) {
                ($this->onMessage)($envelope);
            }

            if ($close) {
                return false;
            }
        }

        return true;
    }

    /** @param list<string> $lines */
    private function write(Connection $connection, array $lines): void
    {
        foreach ($lines as $line) {
            @fwrite($connection->socket, $line . "\r\n");
        }
    }

    /** @param array<int, Connection> $connections */
    private function dropIdle(array &$connections): void
    {
        foreach ($connections as $key => $connection) {
            if ($connection->idleFor() > $this->idleTimeout) {
                @fwrite($connection->socket, "421 4.4.2 Idle timeout\r\n");
                fclose($connection->socket);
                unset($connections[$key]);
            }
        }
    }
}
