clean:
	rm -rf build

build:
	mkdir build
	ppm --no-intro --compile="src/HttpStream" --directory="build"

update:
	ppm --generate-package="src/HttpStream"

install:
	ppm --no-intro --no-prompt --fix-conflict --install="build/net.intellivoid.http_stream.ppm"

install_fast:
	ppm --no-intro --no-prompt --fix-conflict --skip-dependencies --install="build/net.intellivoid.http_stream.ppm"