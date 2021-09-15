<?php

    /** @noinspection PhpMissingFieldTypeInspection */

    namespace HttpStream;

    use HttpStream\Abstracts\StreamMode;
    use HttpStream\Classes\Utilities;
    use HttpStream\Exceptions\OpenStreamException;
    use HttpStream\Exceptions\RequestRangeNotSatisfiableException;
    use HttpStream\Exceptions\UnsupportedStreamException;
    use HttpStream\Objects\HttpResponse;

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
         * @param string $location
         * @throws OpenStreamException
         * @throws UnsupportedStreamException
         */
        public function __construct(string $location)
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
         */
        private function start_stream()
        {
            $i = $this->start;
            ob_get_clean();
            set_time_limit(0);
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

                echo $data;
                flush();
                $i += $bytesToRead;
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
         * @param array $custom_headers
         * @throws RequestRangeNotSatisfiableException
         * @throws UnsupportedStreamException
         */
        public function stream(bool $include_headers=true, bool $as_attachment=true, array $custom_headers=[])
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
                    foreach($headers as $header => $header_value)
                    {
                        header("$header: $header_value");
                    }

                    foreach($custom_headers as $header => $header_value)
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
                foreach($headers as $header => $header_value)
                {
                    header("$header: $header_value");
                }

                foreach($custom_headers as $header => $header_value)
                {
                    header("$header: $header_value");
                }
            }

            $this->start_stream();
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
         * Streams the given location as a HTTP response
         *
         * @param string $location
         * @param bool $as_attachment
         * @param array $headers
         * @throws OpenStreamException
         * @throws RequestRangeNotSatisfiableException
         * @throws UnsupportedStreamException
         */
        public static function streamToHttp(string $location, bool $as_attachment=false, array $headers=[])
        {
            $HttpStream = new HttpStream($location);
            $HttpStream->stream(true, $as_attachment, $headers);
        }

        /**
         * Streams the given location to the stdout
         *
         * @param string $location
         * @throws OpenStreamException
         * @throws RequestRangeNotSatisfiableException
         * @throws UnsupportedStreamException
         */
        public static function streamToStdout(string $location)
        {
            $HttpStream = new HttpStream($location);
            $HttpStream->stream(false);
        }

        /**
         * Releases the file handle resource when destructing the file
         */
        public function __destruct()
        {
            fclose($this->stream);
        }
    }