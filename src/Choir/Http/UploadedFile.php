<?php

declare(strict_types=1);

namespace Choir\Http;

use Choir\Exception\ValidatorException;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;

class UploadedFile implements UploadedFileInterface
{
    private ?string $client_filename;

    private ?string $client_media_type;

    /**
     * @var int
     */
    private $error;

    /**
     * @var null|string
     */
    private $file;

    private bool $moved = false;

    private ?int $size;

    private array $fileinfo;

    /**
     * @throws ValidatorException
     */
    public function __construct(array $fileinfo)
    {
        $this->fileinfo = $fileinfo;

        // 验证上传文件的信息真实有效
        if (!isset($fileinfo['key'], $fileinfo['name'], $fileinfo['error'], $fileinfo['tmp_name'], $fileinfo['size'])) {
            throw new ValidatorException('uploaded file needs ' . implode(', ', ['key', 'name', 'error', 'tmp_name', 'size']));
        }

        $this->client_filename = $this->fileinfo['name'];
        $this->client_media_type = $this->fileinfo['type'] ?? null;
        $this->error = $this->fileinfo['error'];
        $this->size = $this->fileinfo['size'];

        if ($this->isOk()) {
            $this->file = $this->fileinfo['tmp_name'];
        }
    }

    public function isMoved(): bool
    {
        return $this->moved;
    }

    public function getStream(): StreamInterface
    {
        $this->validateActive();

        /** @var string $file */
        $file = $this->file;

        return Stream::create(fopen($file, 'r+'));
    }

    public function moveTo($targetPath): void
    {
        $this->validateActive();

        if ($this->isStringNotEmpty($targetPath) === false) {
            throw new \InvalidArgumentException(
                'Invalid path provided for move operation; must be a non-empty string'
            );
        }

        $this->moved = PHP_SAPI === 'cli' || PHP_SAPI === 'micro'
            ? rename($this->file, $targetPath)
            : move_uploaded_file($this->file, $targetPath);

        if ($this->moved === false) {
            throw new \RuntimeException(
                sprintf('Uploaded file could not be moved to %s', $targetPath)
            );
        }
    }

    public function getSize(): ?int
    {
        return $this->size;
    }

    public function getError(): int
    {
        return $this->error;
    }

    public function getClientFilename(): ?string
    {
        return $this->client_filename;
    }

    public function getClientMediaType(): ?string
    {
        return $this->client_media_type;
    }

    private function isStringNotEmpty($param): bool
    {
        return is_string($param) && empty($param) === false;
    }

    /**
     * Return true if there is no upload error
     */
    private function isOk(): bool
    {
        return $this->error === UPLOAD_ERR_OK;
    }

    /**
     * @throws \RuntimeException if is moved or not ok
     */
    private function validateActive(): void
    {
        if ($this->isOk() === false) {
            throw new \RuntimeException('Cannot retrieve stream due to upload error');
        }

        if ($this->isMoved()) {
            throw new \RuntimeException('Cannot retrieve stream after it has already been moved');
        }
    }
}
