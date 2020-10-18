# PHP secure proxy demo

A secure HTTP proxy implementation, intended as a demo rather than a battle-tested solution.

## About

A secure HTTP proxy is one that only accepts encrypted TLS connections, compared to a common HTTP
proxy which accepts unencrypted connections. Both types allow a client to make http and https
requests to an origin-sever.

### Terminology

In the interests of clarity, this documentation uses the following terms:

* **basic-proxy** refers to a proxy server that receives HTTP messages over an _unencrypted_
  connection.
    * **basic-proxy >http** and **basic-proxy >https** refer to requests from the client for
      resources on an origin-server, using the http and https protocols respectively.

* **secure-proxy** refers to a proxy server that receives HTTP messages over an _encrypted_
  connection.
    * **secure-proxy >http** and **secure-proxy >https** refer to requests from the client for
      resources on an origin-server, using the http and https protocols respectively.

### Main files
This repo contains a simple proxy server `server.php`, which can be run as either a **secure-proxy**
or a **basic-proxy**.

Additionally, three client scripts are provided to illustrate different capabilities:

* `runclient.php` Uses sockets. Supports **secure-proxy >https**.
* `runcurl.php` Uses _curl_. Only later versions support **secure-proxy**.
* `runstreams.php` Uses _file_get_contents_. No **secure-proxy >https** support.

### Contents

* [Installation](#installation)
* [Getting started](#getting-started)
* [Configuration](#configuration)
* [Server](#server)
* [Clients](#clients)



## Installation

Requires PHP 7.0 minimum. Download this repo and install the dependencies with Composer. For
example:

```
$ git clone https://github.com/johnstevenson/proxy-demo.git
$ cd proxy-demo
$ composer install
```

## Getting started

Start a **basic-proxy** out-of-the-box by running:

```
$ php server.php --proxy http
```

This listens on `localhost:6180` until you stop it by pressing **CTRL+C**. Open another terminal and
test it with:

```
$ php runstreams.php --proxy http --target http
```
This downloads content from http://example.com. Now fetch this content over https, showing the
returned headers with the `-v` option:

```
$ php runstreams.php --proxy http --target https -v
```
### Server output

Switch back to the server terminal and the output should be something like:

```
[1] 127.0.0.1:57806 GET / HTTP/1.0
[1] 127.0.0.1:57810 CONNECT example.com:443 HTTP/1.0
```

The numbers represent the id of each client connection. They increment by 2 because the proxy makes
a connection to the origin-server and this uses up an id. You can see these connections and the
client headers with the `-v` option:

```
$ php server.php --proxy http -v

[1] 127.0.0.1:57814 New connection
[1] 127.0.0.1:57814 GET / HTTP/1.0
      Host: example.com
      Connection: close
      User-Agent: PHP/7.2.24-0ubuntu0.18.04.3 (proxy-demo-streams)
[1] 172.20.53.124:48874 Proxy connection to 93.184.216.34:80
[1] 127.0.0.1:57818 New connection
[1] 127.0.0.1:57818 CONNECT example.com:443 HTTP/1.0
[1] 172.20.53.124:60984 Proxy connection to 93.184.216.34:443
```

## Configuration

Configuration is read from `default.conf` and merged with user settings from either `settings.conf`
if it exists, or a file name supplied on the command-line. These files are ini files with values
listed in specific sections.

Use `example.conf` as a template for your user settings by copying it to `settings.conf`.

### Proxy settings

A **secure-proxy** requires a public domain name and certificate data. Follow the instructions to
[generate certificate data](certificates/README.md) then add the proxy domain name and port to your
`settings.conf`. For example:

```ini
[https-proxy]
host = my.domain.com
port = 8443
```

Add the proxy domain name to your hosts file:

```
;/etc/hosts or C:\Windows\System32\drivers\etc\hosts

127.0.0.1 my.domain.com
```

### Request settings

You can add the target urls to fetch for http and https requests in `settings.conf`, as well as
change the request context-options.

## Server

Command-line options:

```
$ php server.php -h

Usage:
    server.php --proxy <scheme> [options]

Options:
  -p --proxy <scheme>   Proxy scheme from config [http|https].
  -c --config <file>    Config file other than settings.conf.
  -v --verbose          Show more output.
  -h --help             Show this screen.

Press Ctrl+C to stop the server.
```

The `--proxy` param indicates the configuration value to use for the proxy domain name and port.

Notes:
* The implementation is probably not that robust.
* Only the GET, HEAD, OPTIONS and CONNECT request methods are supported.
* Non-tunnel requests use HTTP/1.0.


## Clients

All three clients (runclient.php, runcurl.php and runstreams.php) use the same command-line options:

```
$ php runclient.php -h

Usage:
    runclient.php --proxy=<scheme|url> [options]

Options:
  -p --proxy=<scheme|url>   Proxy url from config [http|https], or a specific url.
  -t --target=<scheme|url>  Target url from config [default: http], or a specific url.
  -c --config=<file>        Config file other than settings.conf.
  -v --verbose              Show more output.
  -h --help                 Show this screen.
```

The `--proxy` param either indicates the configuration value to use for the proxy domain name and
port, or it can be a url comprising: [scheme,] ipaddress | domain, port.

The `--target` param either indicates the configuration value to use for the request url, or it can
be a url (http will be used if the scheme is missing).

Not all clients have the same capabilities:

|            | basic-proxy >http | basic-proxy >https | secure-proxy >http | secure-proxy >https |
|------------|-------------------|--------------------|--------------------|---------------------|
| runclient  | Yes               | Yes                | Yes                | Yes                 |
| runcurl    | Yes               | Yes                | Yes (PHP 7.3)      | Yes (PHP 7.3)       |
| runstreams | Yes               | Yes                | Yes                | No                  |

Notes:
* runclient does not support redirects.
* runcurl needs the `curl` extension.
