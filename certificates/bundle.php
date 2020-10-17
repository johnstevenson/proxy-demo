<?php

require __DIR__.'/../vendor/autoload.php';

$doc = <<<DOC
Certificate Bundler.

Usage:
    bundle.php [ --cert FILE, --bundle FILE, --output FILE ]

Options:
  -c --cert FILE    Primary certificate file [default: certificate.crt].
  -b --bundle FILE  CA-Bundle file [default: ca_bundle.crt].
  -o --output FILE  Output file [default: cert_bundle.crt].
  -h --help         Show this message.
DOC;

$docOpt = Docopt::handle($doc, ['exitFullUsage' => true]);
$params = $docOpt->args;

$ds = DIRECTORY_SEPARATOR;
$dir =  __DIR__.'/';

$first = strrpos($params['--cert'], $ds) ? $params['--cert'] : $dir.$params['--cert'];
$second = strrpos($params['--bundle'], $ds) ? $params['--bundle'] : $dir.$params['--bundle'];
$output = strrpos($params['--output'], $ds) ? $params['--output'] : $dir.$params['--output'];

if (!file_exists($first) || !file_exists($second)) {
    echo 'Missing certificate file', PHP_EOL;
    exit(1);
}

if (file_exists($output)) {
    echo 'Output file already exists: '.$output, PHP_EOL;
    exit(1);
}

$files = [
    trim(file_get_contents($first)),
    trim(file_get_contents($second)),
];

file_put_contents($output, implode("\n", $files));
exit(0);
