<?php

    /** @noinspection PhpMissingFieldTypeInspection */

    namespace HttpStream;

    use Defuse\Crypto\Core;
    use Defuse\Crypto\Exception\CryptoException;
    use Defuse\Crypto\Exception\EnvironmentIsBrokenException;
    use Defuse\Crypto\Exception\IOException;
    use Defuse\Crypto\Exception\WrongKeyOrModifiedCiphertextException;
    use Defuse\Crypto\File;
    use Defuse\Crypto\KeyOrPassword;
    use HttpStream\Abstracts\StreamMode;
    use HttpStream\Classes\Utilities;
    use HttpStream\Exceptions\OpenStreamException;
    use HttpStream\Exceptions\RequestRangeNotSatisfiableException;
    use HttpStream\Exceptions\UnsupportedStreamException;
    use HttpStream\Objects\HttpResponse;
    use TmpFile\TmpFile;
    use function array_shift;
    use function fseek;
    use function ftell;
    use function hash_copy;
    use function hash_final;
    use function hash_init;
    use function hash_update;
    use function is_int;
    use function is_object;
    use function is_resource;
    use function is_string;
    use function openssl_decrypt;

    class HttpStream
    {
        /**
         * The streaming mode that the library will attempt to do
         *
         * @var null|string
         */
        private $stream_mode = null;

        /**
         * The selected path to stream from
         *
         * @var string|null
         */
        private $location = null;

        /**
         * The current stream resource
         *
         * @var resource
         */
        private $stream = null;

        /**
         * The buffer size
         *
         * @var int
         */
        private $buffer = 102400;

        /**
         * The start offset
         *
         * @var int
         */
        private $start = -1;

        /**
         * The end offset
         *
         * @var int
         */
        private $end = -1;

        /**
         * The total size of the content
         *
         * @var int
         */
        private $size = 0;

        /**
         * @var KeyOrPassword
         */
        private $encryption_key = null;

        /**
         * @var bool
         */
        private bool $encrypted;

        /**
         * @param string $location
         * @param bool $encrypted
         * @param bool $use_password
         * @param $encryption_key
         * @throws OpenStreamException
         * @throws UnsupportedStreamException
         */
        public function __construct(string $location, bool $encrypted=false, bool $use_password=false, $encryption_key=null)
        {
            if(file_exists($location))
            {
                $this->stream_mode = StreamMode::LocalFileStream;
            }
            elseif(filter_var($location, FILTER_VALIDATE_URL))
            {
                $this->stream_mode = StreamMode::HttpStream;
            }
            else
            {
                if (stripos($location, '://'))
                {
                    throw new UnsupportedStreamException('The given schema \'' . substr($location, 0, stripos($location, '://') + 3) . '\' is not supported');
                }

                throw new OpenStreamException('The given file \'' . $location . '\' was not found');
            }

            if($encryption_key !== null)
            {
                if($use_password)
                {
                    $encryption_key = KeyOrPassword::createFromPassword($encryption_key);
                }
                else
                {
                    $encryption_key = KeyOrPassword::createFromKey($encryption_key);
                }
            }

            $this->encrypted = $encrypted;
            $this->encryption_key = $encryption_key;
            $this->location = $location;
            $this->start = 0;
            $this->size = Utilities::getContentLength($this);
            $this->end = $this->size - 1;

            if (!($this->stream = fopen($this->location, 'rb')))
                throw new OpenStreamException('There was an error while trying to open up the stream');
        }

        /**
         * Returns a pre-calculated HTTP Response for the HTTP Stream
         *
         * @param bool $as_attachment Indicates if the response should be treated as an attachment or not (Download or Stream)
         * @return HttpResponse
         * @throws UnsupportedStreamException
         * @noinspection PhpUnusedLocalVariableInspection
         * @noinspection DuplicatedCode
         */
        public function getHttpResponse(bool $as_attachment=True): HttpResponse
        {
            $HttpResponse = new HttpResponse();

            $HttpResponse->ResponseHeaders['Content-Type'] = Utilities::getContentType($this);
            $HttpResponse->ResponseHeaders['Accept-Ranges'] = '0-' . $this->end;
            $HttpResponse->ResponseHeaders['Content-Range'] = 'bytes ' . $this->start . '-' . $this->end . '/' . $this->size;

            if($as_attachment)
            {
                $HttpResponse->ResponseHeaders['Content-Disposition'] = 'attachment; filename="' . Utilities::getFileName($this) . '"';
            }
            else
            {
                $HttpResponse->ResponseHeaders['Content-Disposition'] = 'filename="' . Utilities::getFileName($this) . '"';
            }

            if (isset($_SERVER['HTTP_RANGE']))
            {
                $c_start = $this->start;
                $c_end = $this->end;

                list(, $range) = explode('=', $_SERVER['HTTP_RANGE'], 2);
                if (strpos($range, ',') !== false)
                {
                    $HttpResponse->ResponseCode = 416;
                    return $HttpResponse;
                }

                if ($range == '-')
                {
                    $c_start = $this->size - substr($range, 1);
                }
                else
                {
                    $range = explode('-', $range);
                    $c_start = $range[0];

                    $c_end = (isset($range[1]) && is_numeric($range[1])) ? $range[1] : $c_end;
                }

                $c_end = ($c_end > $this->end) ? $this->end : $c_end;

                if ($c_start > $c_end || $c_start > $this->size - 1 || $c_end >= $this->size)
                {
                    $HttpResponse->ResponseCode = 416;
                    return $HttpResponse;
                }

                $length = $c_end - $c_start + 1;

                $HttpResponse->ResponseCode = 206;
                $HttpResponse->ResponseHeaders['Content-Length'] =  $length;
            }
            else
            {
                $HttpResponse->ResponseHeaders['Content-Length'] =  $this->size;
            }

            return $HttpResponse;
        }

        /**
         * Prepares the current stream and stream variables
         *
         * @throws RequestRangeNotSatisfiableException
         * @noinspection DuplicatedCode
         * @noinspection PhpUnusedLocalVariableInspection
         */
        public function prepareStream()
        {
            if (isset($_SERVER['HTTP_RANGE']))
            {
                $c_start = $this->start;
                $c_end = $this->end;

                list(, $range) = explode('=', $_SERVER['HTTP_RANGE'], 2);

                if (strpos($range, ',') !== false)
                {
                    throw new RequestRangeNotSatisfiableException('The requested range is not satisfiable', 416);
                }

                if ($range == '-')
                {
                    $c_start = $this->size - substr($range, 1);
                }
                else
                {
                    $range = explode('-', $range);
                    $c_start = $range[0];

                    $c_end = (isset($range[1]) && is_numeric($range[1])) ? $range[1] : $c_end;
                }

                $c_end = ($c_end > $this->end) ? $this->end : $c_end;

                if ($c_start > $c_end || $c_start > $this->size - 1 || $c_end >= $this->size)
                {
                    throw new RequestRangeNotSatisfiableException('The requested range is not satisfiable', 416);
                }

                $this->start = $c_start;
                $this->end = $c_end;
                $length = $this->end - $this->start + 1;
                fseek($this->stream, $this->start);
            }
        }

        /**
         * Begins to transmit the stream to the output
         * @param null $out_resource
         * @throws IOException !
         * @throws WrongKeyOrModifiedCiphertextException
         * @throws CryptoException
         * @throws EnvironmentIsBrokenException
         * @throws OpenStreamException
         * @noinspection DuplicatedCode
         * @noinspection PhpRedundantVariableDocTypeInspection
         */
        public function start_stream($out_resource=null)
        {
            $write_to_resource = false;

            if(is_resource($out_resource))
                $write_to_resource = true;

            $i = $this->start;

            if($write_to_resource == false)
                ob_get_clean();
            set_time_limit(0);

            // Stream the encrypted string
            if($this->encrypted)
            {
                $WorkingStream = $this->stream;
                if($this->stream_mode !== StreamMode::LocalFileStream)
                {
                    // Not all streams support seeking. For those that do not support seeking, forward seeking from
                    // the current position is accomplished by reading and discarding data; other forms of seeking
                    // will fail. https://www.php.net/manual/en/function.fseek.php
                    // To fix this, create another stream and determine how many bytes to download and work with that
                    // Open a new stream
                    $TemporaryStream = fopen($this->location, 'rb');
                    $TemporaryFile = new TmpFile(Utilities::readBytes($this->stream, 1024));
                    fclose($TemporaryStream);
                    $WorkingStream = fopen($TemporaryFile->getFileName(), 'rb');
                }

                /* Check the version header. */
                $header = File::readBytes($WorkingStream, Core::HEADER_VERSION_SIZE);
                if ($header !== Core::CURRENT_VERSION)
                {
                    throw new WrongKeyOrModifiedCiphertextException('Bad version header.');
                }

                /* Get the salt. */
                $file_salt = File::readBytes($WorkingStream, Core::SALT_BYTE_SIZE);

                /* Get the IV. */
                $ivsize = Core::BLOCK_BYTE_SIZE;
                $iv = File::readBytes($WorkingStream, $ivsize);

                /* Derive the authentication and encryption keys. */
                $keys = $this->encryption_key->deriveKeys($file_salt);
                $ekey = $keys->getEncryptionKey();
                $akey = $keys->getAuthenticationKey();

                /* We'll store the MAC of each buffer-sized chunk as we verify the
                 * actual MAC, so that we can check them again when decrypting. */
                $macs = [];
                /* $thisIv will be incremented after each call to the decryption. */
                $thisIv = $iv;


                /* Get the HMAC. */
                if (fseek($WorkingStream, -1 * Core::MAC_BYTE_SIZE, SEEK_END) === -1)
                {
                    throw new IOException('Cannot seek to beginning of MAC within input file');
                }

                /* Get the position of the last byte in the actual ciphertext. */
                /** @var int $cipher_end */
                $cipher_end = ftell($WorkingStream);
                if (!is_int($cipher_end))
                {
                    throw new IOException('Cannot read input file');
                }

                /* We have the position of the first byte of the HMAC. Go back by one. */
                --$cipher_end;

                /* Read the HMAC. */
                /** @var string $stored_mac */
                $stored_mac = File::readBytes($WorkingStream, Core::MAC_BYTE_SIZE);

                /* Initialize a streaming HMAC state. */
                /** @var mixed $hmac */
                $hmac = hash_init(Core::HASH_FUNCTION_NAME, HASH_HMAC, $akey);
                Core::ensureTrue(is_resource($hmac) || is_object($hmac), 'Cannot initialize a hash context');


                if($this->stream_mode !== StreamMode::LocalFileStream)
                {
                    // Restart the stream and go to the actual cipher text
                    $this->stream = fopen($this->location, 'rb');
                    $WorkingStream = $this->stream;
                    Utilities::readBytes($this->stream, Core::HEADER_VERSION_SIZE + Core::SALT_BYTE_SIZE + $ivsize, true);
                }
                else
                {
                    /* Reset file pointer to the beginning of the file after the header */
                    if (fseek($WorkingStream, Core::HEADER_VERSION_SIZE, SEEK_SET) === -1)
                    {
                        throw new IOException('Cannot read seek within input file');
                    }
                    /* Seek to the start of the actual ciphertext. */
                    if (fseek($WorkingStream, Core::SALT_BYTE_SIZE + $ivsize, SEEK_CUR) === -1)
                    {
                        throw new IOException('Cannot seek input file to beginning of ciphertext');
                    }
                }

                /* PASS #1: Calculating the HMAC. */
                hash_update($hmac, $header);
                hash_update($hmac, $file_salt);
                hash_update($hmac, $iv);
                /** @var mixed $hmac2 */
                $hmac2 = hash_copy($hmac);

                if($this->stream_mode == StreamMode::LocalFileStream)
                {
                    $break = false;
                    while (!$break) {
                        /** @var int $pos */
                        $pos = ftell($this->stream);
                        if (!is_int($pos)) {
                            throw new IOException('Could not get current position in input file during decryption');
                        }
                        /* Read the next buffer-sized chunk (or less). */
                        if ($pos + Core::BUFFER_BYTE_SIZE >= $cipher_end) {
                            $break = true;
                            $read = File::readBytes($this->stream, $cipher_end - $pos + 1);
                        } else {
                            $read = File::readBytes($this->stream, Core::BUFFER_BYTE_SIZE);
                        }
                        /* Update the HMAC. */
                        hash_update($hmac, $read);
                        /* Remember this buffer-sized chunk's HMAC. */
                        /** @var mixed $chunk_mac */
                        $chunk_mac = hash_copy($hmac);
                        Core::ensureTrue(is_resource($chunk_mac) || is_object($chunk_mac), 'Cannot duplicate a hash context');
                        $macs[] = hash_final($chunk_mac);
                    }

                    /* Get the final HMAC, which should match the stored one. */
                    /** @var string $final_mac */
                    $final_mac = hash_final($hmac, true);
                    /* Verify the HMAC. */
                    if (!Core::hashEquals($final_mac, $stored_mac))
                    {
                        throw new WrongKeyOrModifiedCiphertextException('Integrity check failed.');
                    }
                }


                /* PASS #2: Decrypt and write output. */
                /* Rewind to the start of the actual ciphertext. */
                if($this->stream_mode !== StreamMode::LocalFileStream)
                {
                    // Close the working stream
                    fclose($WorkingStream);

                    // Restart the stream and go to the actual cipher text
                    $this->stream = fopen($this->location, 'rb');
                    Utilities::readBytes($this->stream, Core::SALT_BYTE_SIZE + $ivsize + Core::HEADER_VERSION_SIZE, true);
                }
                else
                {
                    /* Seek to the start of the actual ciphertext. */
                    if (fseek($this->stream, Core::SALT_BYTE_SIZE + $ivsize + Core::HEADER_VERSION_SIZE, SEEK_SET) === -1)
                    {
                        throw new IOException('Cannot seek input file to beginning of ciphertext');
                    }
                }

                $at_file_end = false;
                $inc = (int) ($this->buffer / Core::BLOCK_BYTE_SIZE);
                while (!$at_file_end)
                {
                    /** @var int $pos */
                    $pos = ftell($this->stream);
                    if (!is_int($pos)) {
                        throw new IOException('Could not get current position in input file during decryption');
                    }
                    /* Read the next buffer-sized chunk (or less). */
                    if ($pos + $this->buffer >= $this->end)
                    {
                        $at_file_end = true;
                        $read = File::readBytes($this->stream, $this->end - $pos + 1);
                    }
                    else
                    {
                        $read = File::readBytes($this->stream, $this->buffer);
                    }

                    /* Recalculate the MAC (so far) and compare it with the one we
                     * remembered from pass #1 to ensure attackers didn't change the
                     * ciphertext after MAC verification. */
                    hash_update($hmac2, $read);
                    /** @var mixed $calc_mac */
                    $calc_mac = hash_copy($hmac2);
                    Core::ensureTrue(is_resource($calc_mac) || is_object($calc_mac), 'Cannot duplicate a hash context');
                    $calc = hash_final($calc_mac);

                    if($this->stream_mode == StreamMode::LocalFileStream)
                    {
                        if (empty($macs))
                        {
                            throw new WrongKeyOrModifiedCiphertextException('File was modified after MAC verification');
                        }
                        elseif (!Core::hashEquals(array_shift($macs), $calc))
                        {
                            throw new WrongKeyOrModifiedCiphertextException('File was modified after MAC verification');
                        }
                    }

                    /* Decrypt this buffer-sized chunk. */
                    /** @var string $decrypted */
                    $decrypted = openssl_decrypt($read, Core::CIPHER_METHOD, $ekey, OPENSSL_RAW_DATA, $thisIv);
                    Core::ensureTrue(is_string($decrypted), 'OpenSSL decryption error');

                    /* Write the plaintext to the output file. */
                    if($write_to_resource)
                    {
                        File::writeBytes($out_resource, $decrypted, Core::ourStrlen($decrypted));
                    }
                    else
                    {
                        echo $decrypted;
                        flush();
                    }

                    /* Increment the IV by the amount of blocks in a buffer. */
                    /** @var string $thisIv */
                    $thisIv = Core::incrementCounter($thisIv, $inc);
                    /* WARNING: Usually, unless the file is a multiple of the buffer
                     * size, $thisIv will contain an incorrect value here on the last
                     * iteration of this loop. */
                }
            }
            else
            {
                while(!feof($this->stream) && $i <= $this->end)
                {
                    $bytesToRead = $this->buffer;
                    if(($i+$bytesToRead) > $this->end)
                        $bytesToRead = $this->end - $i + 1;

                    $data = fread($this->stream, $bytesToRead);
                    $receivedBytes = strlen($data); // Keep track of the missing bytes
                    $supportedBandwidth = $receivedBytes; // Remember the value

                    // Account that the source cannot the requested bytes
                    if($receivedBytes < $bytesToRead)
                    {
                        $bytesLeft = $bytesToRead - $receivedBytes;

                        // First get the missing bytes before the next iteration
                        while($bytesLeft <= 0)
                        {
                            $data .= fread($this->stream, $bytesLeft);
                            $receivedBytes = strlen($data);
                            $bytesLeft = $bytesToRead - $receivedBytes;
                            if($bytesLeft < 0)
                                $bytesLeft = 0;
                        }

                        // Finally, set the new buffer size for the next itration
                        $this->setBuffer($supportedBandwidth);
                    }

                    if($write_to_resource)
                    {
                        fwrite($out_resource, $data);
                    }
                    else
                    {
                        echo $data;
                        flush();

                    }

                    $i += $bytesToRead;
                }
            }

        }

        /**
         * @return int
         * @noinspection PhpUnused
         */
        public function getBuffer(): int
        {
            return $this->buffer;
        }

        /**
         * @param int $buffer
         * @noinspection PhpUnused
         */
        public function setBuffer(int $buffer): void
        {
            $this->buffer = $buffer;
        }

        /**
         * Begins the file stream
         *
         * @param bool $include_headers
         * @param bool $as_attachment
         * @throws CryptoException
         * @throws EnvironmentIsBrokenException
         * @throws IOException
         * @throws OpenStreamException
         * @throws RequestRangeNotSatisfiableException
         * @throws UnsupportedStreamException
         * @throws WrongKeyOrModifiedCiphertextException
         */
        public function stream(bool $include_headers=true, bool $as_attachment=true)
        {
            $headers = $this->getHttpResponse($as_attachment);

            try
            {
                $this->prepareStream();
            }
            catch (RequestRangeNotSatisfiableException $e)
            {
                if($include_headers)
                {
                    http_response_code($headers->ResponseCode);
                    foreach($headers->ResponseHeaders as $header => $header_value)
                    {
                        header("$header: $header_value");
                    }

                    return;
                }

                throw $e;
            }

            if($include_headers)
            {
                http_response_code($headers->ResponseCode);
                foreach($headers->ResponseHeaders as $header => $header_value)
                {
                    header("$header: $header_value");
                }
            }

            $this->start_stream();
        }

        /**
         * @param $resource
         * @throws CryptoException
         * @throws EnvironmentIsBrokenException
         * @throws IOException
         * @throws OpenStreamException
         * @throws WrongKeyOrModifiedCiphertextException
         */
        public function streamResource($resource)
        {
            $this->start_stream($resource);
        }

        /**
         * @return string|null
         */
        public function getStreamMode(): ?string
        {
            return $this->stream_mode;
        }

        /**
         * @return string
         */
        public function getLocation(): string
        {
            return $this->location;
        }

        /**
         * Releases the file handle resource when destructing the file
         */
        public function __destruct()
        {
            fclose($this->stream);
        }

        /**
         * Streams the given location as a HTTP response
         *
         * @param string $location
         * @param bool $as_attachment
         * @throws OpenStreamException
         * @throws RequestRangeNotSatisfiableException
         * @throws UnsupportedStreamException
         * @noinspection PhpDocMissingThrowsInspection
         * @noinspection PhpUnhandledExceptionInspection
         */
        public static function streamToHttp(string $location, bool $as_attachment=false)
        {
            $HttpStream = new HttpStream($location);
            $HttpStream->stream(true, $as_attachment);
        }

        /**
         * Streams the given location as an HTTP response
         *
         * @param string $location
         * @param bool $use_password
         * @param $encryption_key
         * @param bool $as_attachment
         * @throws CryptoException
         * @throws EnvironmentIsBrokenException
         * @throws IOException
         * @throws OpenStreamException
         * @throws RequestRangeNotSatisfiableException
         * @throws UnsupportedStreamException
         * @throws WrongKeyOrModifiedCiphertextException
         */
        public static function streamEncryptedToHttp(string $location, bool $use_password, $encryption_key, bool $as_attachment=false)
        {
            $HttpStream = new HttpStream($location, true, $use_password, $encryption_key);
            $HttpStream->stream(true, $as_attachment);
        }

        /**
         * Streams the given location to the standard output
         *
         * @param string $location
         * @throws OpenStreamException
         * @throws RequestRangeNotSatisfiableException
         * @throws UnsupportedStreamException
         * @noinspection PhpDocMissingThrowsInspection
         * @noinspection PhpUnhandledExceptionInspection
         */
        public static function streamToStdout(string $location)
        {
            $HttpStream = new HttpStream($location);
            $HttpStream->stream(false);
        }


        /**
         * Streams the given encrypted location to the standard output
         *
         * @param string $location
         * @param bool $use_password
         * @param $encryption_key
         * @throws CryptoException
         * @throws EnvironmentIsBrokenException
         * @throws IOException
         * @throws OpenStreamException
         * @throws RequestRangeNotSatisfiableException
         * @throws UnsupportedStreamException
         * @throws WrongKeyOrModifiedCiphertextException
         */
        public static function streamEncryptedToStdout(string $location, bool $use_password, $encryption_key)
        {
            $HttpStream = new HttpStream($location, true, $use_password, $encryption_key);
            $HttpStream->stream(false);
        }

        /**
         * Streams the given location to a resource
         *
         * @param string $location
         * @param $resource
         * @throws OpenStreamException
         * @throws UnsupportedStreamException
         * @noinspection PhpDocMissingThrowsInspection
         * @noinspection PhpUnhandledExceptionInspection
         */
        public static function streamToResource(string $location, $resource)
        {
            $HttpStream = new HttpStream($location);
            $HttpStream->streamResource($resource);
        }

        /**
         * Streams the given encrypted location to a resource
         *
         * @param string $location
         * @param $resource
         * @param bool $use_password
         * @param $encryption_key
         * @throws CryptoException
         * @throws EnvironmentIsBrokenException
         * @throws IOException
         * @throws OpenStreamException
         * @throws UnsupportedStreamException
         * @throws WrongKeyOrModifiedCiphertextException
         */
        public static function streamEncryptedToResource(string $location, $resource, bool $use_password, $encryption_key)
        {
            $HttpStream = new HttpStream($location, true, $use_password, $encryption_key);
            $HttpStream->streamResource($resource);
        }
    }