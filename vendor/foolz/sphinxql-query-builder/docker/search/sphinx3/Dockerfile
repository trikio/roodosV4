FROM ubuntu:24.04

ENV DEBIAN_FRONTEND=noninteractive
ENV SPHINX3_VERSION=3.9.1
ENV SPHINX3_BUILD=141d2ea

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates \
        wget \
        gcc \
        libc6-dev \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /opt/sphinx-tests

RUN wget --quiet "https://sphinxsearch.com/files/sphinx-${SPHINX3_VERSION}-${SPHINX3_BUILD}-linux-amd64.tar.gz" \
    && tar zxf "sphinx-${SPHINX3_VERSION}-${SPHINX3_BUILD}-linux-amd64.tar.gz" \
    && mv "sphinx-${SPHINX3_VERSION}" /opt/sphinx3 \
    && rm -f "sphinx-${SPHINX3_VERSION}-${SPHINX3_BUILD}-linux-amd64.tar.gz"

COPY tests/sphinx.conf /opt/sphinx-tests/sphinx.conf
COPY tests/s3_test_udf.c /opt/sphinx-tests/s3_test_udf.c

RUN mkdir -p /opt/sphinx-tests/data \
    && gcc -shared -I/opt/sphinx3/src -o /opt/sphinx-tests/data/test_udf.so /opt/sphinx-tests/s3_test_udf.c

EXPOSE 9307 9312

CMD ["/opt/sphinx3/bin/searchd", "-c", "/opt/sphinx-tests/sphinx.conf", "--nodetach"]
