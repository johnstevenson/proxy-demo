# Certificates for HTTPS-Proxy

In order to run a secure proxy (ie. a proxy that requires an https connection), you must supply
a certificate and private key in PEM format. This `certificates` directory is the default location
for the required context-option settings:

* **local_cert**: the certificate for the proxy and CA issuer (default: _cert_bundle.crt_)
* **local_pk**: the private key (default: _private.key_)
* **passphrase**: optional password for private key (default: _""_)

## Certificate generation

This proxy is only a demo so it is assumed that it will be running locally. It is recommended
to use a public domain name for your proxy, because it is very easy to set up if you have access to
one. Alternatively you can create a self-signed certificate and disable the _verify_peer_ and
_verify_peer_name_ settings in `settings.conf`.

This example uses [ZeroSSL](https://zerossl.com/) to obtain a free 90-day certificate. It has a
clean and simple web interface which makes the entire process very straight-forward. The
instructions below can be adapted for other providers or certificate generation methods.

Create an account then add a New Certificate for your public domain (or sub-domain) name. Allow the
app to auto-generate a CSR (Certificate Signing Resquest) and private key then select your choice
of domain verification, which uses email, DNS CNAME or file upload methods.

Note that the DNS CNAME method is probably the simplest (if you can add a new DNS entry) because
the endpoint does not have to exist. The verification process makes a DNS query for the CNAME
record provided by ZeroSSL, which allows you to create a new sub-domain for this purpose.

Download your certificate after verification and unzip it to this directory, resulting in:

* **certificate.crt**: the primary certificate file
* **private.key**: the private key file
* **ca_bundle.crt**: the CA issuer file

Create a single certificate bundle `cert_bundle.crt` by running:

```bash
php certificates/bundle.php
```
or do it manually by combining _certificate.crt_ and _ca_bundle.crt_ and ensuring that the second
certificate starts on a new line.

## Configuration

Add your proxy domain and port to `settings.conf`. For example:
```ini
[https-proxy]
host = my.domain.com
port = 8443
```

Add your proxy domain to your `hosts` file:

```
;/etc/hosts or C:\Windows\System32\drivers\etc\hosts

127.0.0.1 my.domain.com
```
