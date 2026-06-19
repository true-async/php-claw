<?php

declare(strict_types=1);

$finder = PhpCsFixer\Finder::create()
    ->in([__DIR__ . '/src', __DIR__ . '/tests'])
    ->append([__DIR__ . '/bin/claw']);

return (new PhpCsFixer\Config())
    ->setUnsupportedPhpVersionAllowed(true)   // runtime is PHP 8.6-dev (TrueAsync)
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR12' => true,
        '@PHP82Migration' => true,
        'array_syntax' => ['syntax' => 'short'],
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'no_unused_imports' => true,
        'single_quote' => true,
        'trailing_comma_in_multiline' => true,
        'declare_strict_types' => true,
        'phpdoc_align' => false,
        'phpdoc_separation' => false,
        'no_superfluous_phpdoc_tags' => false,
    ])
    ->setFinder($finder);
