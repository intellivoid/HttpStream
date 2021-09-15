<?php

    require('ppm');
    import('net.intellivoid.http_stream');

    $HttpStream = new \HttpStream\HttpStream(__DIR__ . DIRECTORY_SEPARATOR. 'example_video.mp4');
    $HttpStream->stream(false, false);