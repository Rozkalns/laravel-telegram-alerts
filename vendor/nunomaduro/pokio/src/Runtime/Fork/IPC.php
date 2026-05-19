<?php

declare(strict_types=1);

namespace Pokio\Runtime\Fork;

use FFI;
use RuntimeException;

/**
 * @internal
 */
final readonly class IPC
{
    private const string FILE_PREFIX = '/tmp/ipc_mem_';

    // POSIX constants
    private const int O_RDWR = 0x0002;

    private const int O_CREAT = 0x0040;

    private const int PROT_READ = 0x1;

    private const int PROT_WRITE = 0x2;

    private const int MAP_SHARED = 0x01;

    /**
     * Creates a new IPC memory block using a memory-mapped file.
     */
    private function __construct(
        private string $path,
    ) {
        //
    }

    /**
     * Creates a new IPC memory block using a memory-mapped file.
     */
    public static function create(): self
    {
        $id = bin2hex(random_bytes(8));
        $path = self::FILE_PREFIX.$id;
        touch($path);

        return new self($path);
    }

    /**
     * Writes data to the memory block.
     */
    public function put(string $data): void
    {
        // @codeCoverageIgnoreStart
        $ffi = self::libc();
        $length = mb_strlen($data, '8bit');

        $fd = $ffi->open($this->path, self::O_RDWR | self::O_CREAT, 0600);
        if ($fd < 0) {
            throw new RuntimeException('Failed to open file for writing');
        }

        $ffi->ftruncate($fd, $length);

        $ptr = $ffi->mmap(null, $length, self::PROT_READ | self::PROT_WRITE, self::MAP_SHARED, $fd, 0);
        $intptr = $ffi->cast('intptr_t', $ptr);

        // @phpstan-ignore-next-line
        if ($intptr === null || $intptr->cdata === -1) {
            throw new RuntimeException('mmap failed to write');
        }

        $ffi->memcpy($ptr, $data, $length);
        $ffi->munmap($ptr, $length);
        $ffi->close($fd);
        // @codeCoverageIgnoreEnd
    }

    /**
     * Reads and clears data from the memory block.
     */
    public function pop(): string
    {
        $ffi = self::libc();

        $fd = $ffi->open($this->path, self::O_RDWR, 0600);
        if ($fd < 0) {
            throw new RuntimeException('Failed to open file for reading');
        }

        $length = filesize($this->path);

        $ptr = $ffi->mmap(null, $length, self::PROT_READ, self::MAP_SHARED, $fd, 0);
        $intptr = $ffi->cast('intptr_t', $ptr);

        // @phpstan-ignore-next-line
        if ($intptr === null || $intptr->cdata === -1) {
            throw new RuntimeException('mmap failed to read');
        }

        $data = FFI::string($ptr, $length);
        $ffi->munmap($ptr, $length);
        $ffi->close($fd);
        unlink($this->path);

        return $data;
    }

    /**
     * Returns the path to the IPC memory block.
     */
    public function path(): string
    {
        return $this->path;
    }

    /**
     * Loads libc and defines function bindings.
     */
    private static function libc(): FFI
    {
        static $ffi = null;

        if ($ffi === null) {
            $lib = PHP_OS_FAMILY === 'Darwin' ? 'libc.dylib' : 'libc.so.6';

            $ffi = FFI::cdef('
                void* mmap(void* addr, size_t length, int prot, int flags, int fd, off_t offset);
                int munmap(void* addr, size_t length);
                int open(const char *pathname, int flags, int mode);
                int close(int fd);
                int ftruncate(int fd, off_t length);
                void* memcpy(void* dest, const void* src, size_t n);
            ', $lib);
        }

        return $ffi; // @phpstan-ignore-line
    }
}
