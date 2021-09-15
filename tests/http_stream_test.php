<?php

    require('ppm');
    import('net.intellivoid.http_stream');

    $HttpStream = new \HttpStream\HttpStream('https://file-examples-com.github.io/uploads/2017/04/file_example_MP4_480_1_5MG.mp4');
    $HttpStream->stream(false, false);