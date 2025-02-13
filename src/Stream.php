<?php

/**
 * League.Csv (https://csv.thephpleague.com)
 *
 * (c) Ignace Nyamagana Butera <nyamsprod@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace League\Csv;

use SeekableIterator;
use SplFileObject;
use TypeError;
use function array_keys;
use function array_walk_recursive;
use function fclose;
use function feof;
use function fflush;
use function fgetcsv;
use function fopen;
use function fpassthru;
use function fputcsv;
use function fread;
use function fseek;
use function fwrite;
use function get_resource_type;
use function gettype;
use function is_array;
use function is_resource;
use function restore_error_handler;
use function rewind;
use function set_error_handler;
use function stream_filter_append;
use function stream_filter_remove;
use function stream_get_meta_data;
use function strlen;
use const SEEK_SET;

/**
 * An object-oriented API to handle a PHP stream resource.
 *
 * @internal used internally to iterate over a stream resource
 */
final class Stream implements SeekableIterator
{
    /** @var resource */
    private $stream;
    private bool $is_seekable;
    private bool $should_close_stream = false;
    /** @var mixed can be a null, false or a scalar type value. Current iterator value. */
    private mixed $value;
    /** Current iterator key. */
    private int $offset;
    /** Flags for the Document. */
    private int $flags = 0;
    private string $delimiter = ',';
    private string $enclosure = '"';
    private string $escape = '\\';
    /** @var array<string, array<resource>> Attached filters. */
    private array $filters = [];

    /**
     * @param resource $stream stream type resource
     */
    private function __construct($stream)
    {
        $this->is_seekable = stream_get_meta_data($stream)['seekable'];
        $this->stream = $stream;
    }

    public function __destruct()
    {
        array_walk_recursive($this->filters, fn ($filter): bool => @stream_filter_remove($filter));

        if ($this->should_close_stream && is_resource($this->stream)) {
            fclose($this->stream);
        }

        unset($this->stream);
    }

    public function __clone(): void
    {
        throw UnavailableStream::dueToForbiddenCloning(self::class);
    }

    public function __debugInfo(): array
    {
        return stream_get_meta_data($this->stream) + [
            'delimiter' => $this->delimiter,
            'enclosure' => $this->enclosure,
            'escape' => $this->escape,
            'stream_filters' => array_keys($this->filters),
        ];
    }

    /**
     * Returns a new instance from a file path.
     *
     * @param resource|null $context
     *
     * @throws UnavailableStream if the stream resource can not be created
     */
    public static function createFromPath(string $path, string $open_mode = 'r', $context = null): self
    {
        $args = [$path, $open_mode];
        if (null !== $context) {
            $args[] = false;
            $args[] = $context;
        }

        set_error_handler(fn (int $errno, string $errstr, string $errfile, int $errline) => true);
        $resource = fopen(...$args);
        restore_error_handler();

        if (!is_resource($resource)) {
            throw UnavailableStream::dueToPathNotFound($path);
        }

        $instance = new self($resource);
        $instance->should_close_stream = true;

        return $instance;
    }

    /**
     * Returns a new instance from a string.
     */
    public static function createFromString(string $content = ''): self
    {
        /** @var resource $resource */
        $resource = fopen('php://temp', 'r+');
        fwrite($resource, $content);

        $instance = new self($resource);
        $instance->should_close_stream = true;

        return $instance;
    }

    public static function createFromResource(mixed $stream): self
    {
        if (!is_resource($stream)) {
            throw new TypeError('Argument passed must be a stream resource, '.gettype($stream).' given.');
        }

        $type = get_resource_type($stream);
        if ('stream' !== $type) {
            throw new TypeError('Argument passed must be a stream resource, '.$type.' resource given');
        }

        return new self($stream);
    }

    /**
     * Returns the URI of the underlying stream.
     *
     * @see https://www.php.net/manual/en/splfileinfo.getpathname.php
     */
    public function getPathname(): string
    {
        return stream_get_meta_data($this->stream)['uri'];
    }

    /**
     * Appends a filter.
     *
     * @see http://php.net/manual/en/function.stream-filter-append.php
     *
     * @throws InvalidArgument if the filter can not be appended
     */
    public function appendFilter(string $filtername, int $read_write, array $params = null): void
    {
        set_error_handler(fn (int $errno, string $errstr, string $errfile, int $errline) => true);
        $res = stream_filter_append($this->stream, $filtername, $read_write, $params ?? []);
        restore_error_handler();
        if (!is_resource($res)) {
            throw InvalidArgument::dueToStreamFilterNotFound($filtername);
        }

        $this->filters[$filtername][] = $res;
    }

    /**
     * Sets CSV control.
     *
     * @see https://www.php.net/manual/en/splfileobject.setcsvcontrol.php
     *
     * @throws InvalidArgument
     */
    public function setCsvControl(string $delimiter = ',', string $enclosure = '"', string $escape = '\\'): void
    {
        [$this->delimiter, $this->enclosure, $this->escape] = $this->filterControl($delimiter, $enclosure, $escape, __METHOD__);
    }

    /**
     * Filters CSV control characters.
     *
     * @throws InvalidArgument If the CSV control character is not exactly one character.
     *
     * @return array{0:string, 1:string, 2:string}
     */
    private function filterControl(string $delimiter, string $enclosure, string $escape, string $caller): array
    {
        return match (true) {
            1 !== strlen($delimiter) => throw InvalidArgument::dueToInvalidDelimiterCharacter($delimiter, $caller),
            1 !== strlen($enclosure) => throw InvalidArgument::dueToInvalidEnclosureCharacter($enclosure, $caller),
            1 !== strlen($escape) && '' !== $escape => throw InvalidArgument::dueToInvalidEscapeCharacter($escape, $caller),
            default => [$delimiter, $enclosure, $escape],
        };
    }

    /**
     * Returns CSV control.
     *
     * @see https://www.php.net/manual/en/splfileobject.getcsvcontrol.php
     *
     * @return array<string>
     */
    public function getCsvControl(): array
    {
        return [$this->delimiter, $this->enclosure, $this->escape];
    }

    /**
     * Sets CSV stream flags.
     *
     * @see https://www.php.net/manual/en/splfileobject.setflags.php
     */
    public function setFlags(int $flags): void
    {
        $this->flags = $flags;
    }

    /**
     * Writes a field array as a CSV line.
     *
     * @see https://www.php.net/manual/en/splfileobject.fputcsv.php
     *
     * @throws InvalidArgument If the CSV control character is not exactly one character.
     */
    public function fputcsv(array $fields, string $delimiter = ',', string $enclosure = '"', string $escape = '\\', string $eol = "\n"): int|false
    {
        return fputcsv(
            $this->stream,
            $fields,
            ...[...$this->filterControl($delimiter, $enclosure, $escape, __METHOD__), $eol]
        );
    }

    /**
     * Gets line number.
     *
     * @see https://www.php.net/manual/en/splfileobject.key.php
     */
    public function key(): int
    {
        return $this->offset;
    }

    /**
     * Reads next line.
     *
     * @see https://www.php.net/manual/en/splfileobject.next.php
     */
    public function next(): void
    {
        $this->value = false;
        $this->offset++;
    }

    /**
     * Rewinds the file to the first line.
     *
     * @see https://www.php.net/manual/en/splfileobject.rewind.php
     *
     * @throws Exception if the stream resource is not seekable
     */
    public function rewind(): void
    {
        if (!$this->is_seekable) {
            throw UnavailableFeature::dueToMissingStreamSeekability();
        }

        rewind($this->stream);
        $this->offset = 0;
        $this->value = false;
        if (0 !== ($this->flags & SplFileObject::READ_AHEAD)) {
            $this->current();
        }
    }

    /**
     * Not at EOF.
     *
     * @see https://www.php.net/manual/en/splfileobject.valid.php
     */
    public function valid(): bool
    {
        if (0 !== ($this->flags & SplFileObject::READ_AHEAD)) {
            return $this->current() !== false;
        }

        return !feof($this->stream);
    }

    /**
     * Retrieves the current line of the file.
     *
     * @see https://www.php.net/manual/en/splfileobject.current.php
     */
    public function current(): mixed
    {
        if (false !== $this->value) {
            return $this->value;
        }

        $this->value = $this->getCurrentRecord();

        return $this->value;
    }

    /**
     * Retrieves the current line as a CSV Record.
     */
    private function getCurrentRecord(): array|false
    {
        $flag = 0 !== ($this->flags & SplFileObject::SKIP_EMPTY);
        do {
            $ret = fgetcsv($this->stream, 0, $this->delimiter, $this->enclosure, $this->escape);
        } while ($flag && is_array($ret) && null === $ret[0]);

        return $ret;
    }

    /**
     * Seeks to specified line.
     *
     * @see https://www.php.net/manual/en/splfileobject.seek.php
     *
     * @throws Exception if the position is negative
     */
    public function seek(int $offset): void
    {
        if ($offset < 0) {
            throw InvalidArgument::dueToInvalidSeekingPosition($offset, __METHOD__);
        }

        $this->rewind();
        while ($this->key() !== $offset && $this->valid()) {
            $this->current();
            $this->next();
        }

        if (0 !== $offset) {
            $this->offset--;
        }

        $this->current();
    }

    /**
     * Outputs all remaining data on a file pointer.
     *
     * @see https://www.php.net/manual/en/splfileobject.fpassthru.php
     */
    public function fpassthru(): int|false
    {
        return fpassthru($this->stream);
    }

    /**
     * Reads from file.
     *
     * @see https://www.php.net/manual/en/splfileobject.fread.php
     *
     * @param int<0, max> $length The number of bytes to read
     */
    public function fread(int $length): string|false
    {
        return fread($this->stream, $length);
    }

    /**
     * Seeks to a position.
     *
     * @see https://www.php.net/manual/en/splfileobject.fseek.php
     *
     * @throws Exception if the stream resource is not seekable
     */
    public function fseek(int $offset, int $whence = SEEK_SET): int
    {
        if (!$this->is_seekable) {
            throw UnavailableFeature::dueToMissingStreamSeekability();
        }

        return fseek($this->stream, $offset, $whence);
    }

    /**
     * Flushes the output to a file.
     *
     * @see https://www.php.net/manual/en/splfileobject.fflush.php
     */
    public function fflush(): bool
    {
        return fflush($this->stream);
    }
}
