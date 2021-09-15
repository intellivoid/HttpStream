<?php

    /** @noinspection PhpMissingFieldTypeInspection */

    namespace HttpStream\Objects;

    class HttpResponse
    {
        /**
         * The HTTP Response code to return
         *
         * @var int
         */
        public $ResponseCode;

        /**
         * The HTTP Response headers to return
         *
         * @var array
         */
        public $ResponseHeaders;

        public function __construct()
        {
            $this->ResponseCode = 200;
            $this->ResponseHeaders = [];
        }

        /**
         * Returns an array representation of the object
         *
         * @return array
         * @noinspection PhpArrayShapeAttributeCanBeAddedInspection
         */
        public function toArray(): array
        {
            return [
                'response_code' => $this->ResponseCode,
                'response_headers' => $this->ResponseHeaders
            ];
        }

        /**
         * Constructs object from an array representation
         *
         * @param array $data
         * @return HttpResponse
         * @noinspection PhpPureAttributeCanBeAddedInspection
         */
        public static function fromArray(array $data): HttpResponse
        {
            $HttpResponseObject = new HttpResponse();

            if(isset($data['response_code']))
                $HttpResponseObject->ResponseCode = $data['response_code'];

            if(isset($data['response_headers']))
                $HttpResponseObject->ResponseHeaders = $data['response_headers'];

            return $HttpResponseObject;
        }
    }