<?php

    require('ppm');
    import('net.intellivoid.http_stream');

    $HttpStream = new \HttpStream\HttpStream('https://file-examples-com.github.io/uploads/2017/04/file_example_MP4_1920_18MG.mp4');
    $HttpStream->stream(false, false);