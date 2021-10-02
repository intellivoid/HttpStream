<?php

    /** @noinspection PhpMissingFieldTypeInspection */

    namespace HttpStream\Classes;

    use Exception;
    use HttpStream\Abstracts\StreamMode;
    use HttpStream\Exceptions\OpenStreamException;
    use HttpStream\Exceptions\UnsupportedStreamException;
    use HttpStream\HttpStream;
    use MimeLib\MimeLib;
    use function extension_loaded;
    use function feof;
    use function fread;
    use function ini_get;
    use function is_string;
    use function mb_strlen;
    use function strlen;

    class Utilities
    {
        /**
         * Holds a cache of the headers so that multiple requests don't have to be made
         *
         * @var array|null
         */
        private static $cache_http_headers;

        /**
         * Holds a cache of the last requested URI so that multiple request don't have to be made
         *
         * @var string|null
         */
        private static $cache_http_uri;

        /**
         * Updates the HTTP Cache
         *
         * @param HttpStream $httpStream
         */
        private static function updateHttpCache(HttpStream $httpStream)
        {
            if(self::$cache_http_uri !== null && $httpStream->getLocation() == self::$cache_http_uri)
            {
                return;
            }

            $headers = self::getHttpHeaders($httpStream->getLocation());

            self::$cache_http_uri = $httpStream->getLocation();
            self::$cache_http_headers = $headers;
        }

        /**
         * Returns a random file name
         *
         * @param int $length
         * @return string
         */
        public static function randomFileName(int $length): string
        {
            $key = (string)null;
            $keys = array_merge(range(0, 9), range('a', 'z'));
            $key .= str_repeat($keys[array_rand($keys)], $length);
            return $key;

        }

        /**
         * Attempts to get the content length of the supported stream
         *
         * @param HttpStream $httpStream
         * @return int|null
         * @throws UnsupportedStreamException
         */
        public static function getContentLength(HttpStream $httpStream): ?int
        {
            switch($httpStream->getStreamMode())
            {
                case StreamMode::HttpStream:
                    self::updateHttpCache($httpStream);

                    if(isset(self::$cache_http_headers['Content-Length']))
                        return (int)self::$cache_http_headers['Content-Length'];
                    return null;

                case StreamMode::LocalFileStream:
                    return filesize($httpStream->getLocation());
            }

            throw new UnsupportedStreamException('The requested stream mode \'' . $httpStream->getStreamMode() . '\' is not supported for identifying the content size');
        }

        /**
         * Attempts to get the content length of the supported stream
         *
         * @param HttpStream $httpStream
         * @return string
         * @throws UnsupportedStreamException
         */
        public static function getContentType(HttpStream $httpStream): string
        {
            switch($httpStream->getStreamMode())
            {
                case StreamMode::HttpStream:
                    self::updateHttpCache($httpStream);

                    if(isset(self::$cache_http_headers['Content-Type']))
                        return self::$cache_http_headers['Content-Type'];
                    return 'application/octet-stream';

                case StreamMode::LocalFileStream:
                    try
                    {
                        return MimeLib::detectFileType($httpStream->getLocation())->getMime();
                    }
                    catch (Exception $e)
                    {
                        return 'application/octet-stream';
                    }
            }

            throw new UnsupportedStreamException('The requested stream mode \'' . $httpStream->getStreamMode() . '\' is not supported for identifying the content type');
        }

        /**
         * Returns the file name of the HTTP Stream
         *
         * @param HttpStream $httpStream
         * @return string
         * @throws UnsupportedStreamException
         */
        public static function getFileName(HttpStream $httpStream): string
        {
            switch($httpStream->getStreamMode())
            {
                case StreamMode::HttpStream:
                    self::updateHttpCache($httpStream);

                    if(isset(self::$cache_http_headers['Content-Disposition']))
                    {
                        if (preg_match('/Content-Disposition:.*?filename="(.+?)"/', self::$cache_http_headers['Content-Disposition'], $matches))
                            return $matches[1];
                        if (preg_match('/Content-Disposition:.*?filename=([^; ]+)/', self::$cache_http_headers['Content-Disposition'], $matches))
                            return rawurldecode($matches[1]);
                    }

                    $base_name = basename(self::$cache_http_uri);
                    if(strlen($base_name) == 0)
                        return self::randomFileName(16);
                    return $base_name;

                case StreamMode::LocalFileStream:
                    return basename($httpStream->getLocation());
            }

            throw new UnsupportedStreamException('The requested stream mode \'' . $httpStream->getStreamMode() . '\' is not supported for identifying the file name');
        }

        /**
         * Returns the headers of a HTTP request only
         *
         * @param string $url
         * @return array
         */
        public static function getHttpHeaders(string $url): array
        {
            $headers = get_headers($url);
            $response_headers = [];
            foreach ($headers as $value)
            {
                if(false !== ($matches = explode(':', $value, 2)))
                {
                    if(count($matches) < 2)
                        continue;
                    $response_headers["{$matches[0]}"] = trim($matches[1]);
                }
            }

            return $response_headers;
        }

        /**
         * Determines the string length
         *
         * @param $str
         * @return false|int
         * @noinspection SpellCheckingInspection
         */
        public static function ourStrlen($str)
        {
            static $exists = null;
            if ($exists === null)
            {
                $exists =
                    extension_loaded('mbstring') &&
                    ini_get('mbstring.func_overload') !== false &&
                    (int) ini_get('mbstring.func_overload') & defined('MB_OVERLOAD_STRING');
            }

            if ($exists)
            {
                return mb_strlen($str, '8bit');
            }

            return strlen($str);
        }

        /**
         * Reads the number of requested bytes
         *
         * @param $stream
         * @param $num_bytes
         * @param bool $end_of_file_check
         * @return string
         * @throws OpenStreamException
         */
        public static function readBytes($stream, $num_bytes, bool $end_of_file_check=false): string
        {
            if ($num_bytes === 0 || $num_bytes < 0)
                return '';
            $buf = '';
            $remaining = $num_bytes;
            while ($remaining > 0 && !feof($stream))
            {
                /** @var string $read */
                $read = fread($stream, $remaining);

                if (!is_string($read))
                {
                    throw new OpenStreamException('Could not read from the file');
                }

                $buf .= $read;
                $remaining -= self::ourStrlen($read);
            }
            if (self::ourStrlen($buf) !== $num_bytes && $end_of_file_check)
                throw new OpenStreamException('Tried to read past the end of the file');
            return $buf;
        }
    }