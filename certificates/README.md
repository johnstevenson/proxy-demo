# Certificate generation

This uses [ZeroSSL](https://zerossl.com/) to obtain a free 90-day certificate. These
instructions can be adapted for other providers or certificate generation methods.

Create an account then add a New Certificate for your public domain (or sub-domain) name. Make sure
you choose the 90-day certificate for single domain option. Agree to auto-generate a CSR
(Certificate Signing Resquest) and private key, then select your choice of domain verification,
which can be done by email, DNS CNAME or file upload methods.

Note that the DNS CNAME method is useful because the endpoint does not have to exist: the ZeroSSL
verification process makes a DNS query for the CNAME record data that it asked you to add, which
allows you to create a new sub-domain just for this purpose.

Download your certificate after verification and unzip it to the `certificates` directory, which
result in:

* **certificate.crt**: the domain certificate file
* **private.key**: the private key file
* **ca_bundle.crt**: the CA issuer file (ZeroSSL is a Trusted Certificate Authority)

Create a single certificate bundle `cert_bundle.crt` by running:

```bash
php certificates/bundle.php
```
or do it manually by combining _certificate.crt_ and _ca_bundle.crt_ and ensuring that the second
certificate starts on a new line.
