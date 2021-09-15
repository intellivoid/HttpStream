<?php

    require('ppm');
    import('net.intellivoid.http_stream');

    \HttpStream\HttpStream::streamToStdout(__DIR__ . DIRECTORY_SEPARATOR. 'example_video.mp4');