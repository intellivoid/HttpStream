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
         * @noinspection PhpStrFunctionsInspection
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
         * @noinspection PhpStrFunctionsInspection
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
            $b = 0;
            ob_get_clean();
            set_time_limit(0);
            while(!feof($this->stream) && $i <= $this->end)
            {
                $b += 1;
                $bytesToRead = $this->buffer;
                if(($i+$bytesToRead) > $this->end)
                    $bytesToRead = $this->end - $i + 1;

                $data = fread($this->stream, $bytesToRead);
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
         * @param bool $exit
         * @param bool $include_headers
         * @param bool $as_attachment
         * @throws RequestRangeNotSatisfiableException
         * @throws UnsupportedStreamException
         */
        public function stream(bool $exit=true, bool $include_headers=true, bool $as_attachment=true)
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

                    if($exit)
                    {
                        exit();
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
            }

            $this->start_stream();

            if($exit)
            {
                exit();
            }
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
    }