<?php

// autoload_static.php @generated by Composer

namespace Composer\Autoload;

class ComposerStaticInit6cde9c56a62cce11c64af3aebd873be7
{
    public static $prefixLengthsPsr4 = array (
        'P' => 
        array (
            'PhpAmqpLib\\' => 11,
        ),
    );

    public static $prefixDirsPsr4 = array (
        'PhpAmqpLib\\' => 
        array (
            0 => __DIR__ . '/..' . '/videlalvaro/php-amqplib/PhpAmqpLib',
            1 => __DIR__ . '/..' . '/php-amqplib/php-amqplib/PhpAmqpLib',
        ),
    );

    public static $classMap = array (
        'Composer\\InstalledVersions' => __DIR__ . '/..' . '/composer/InstalledVersions.php',
    );

    public static function getInitializer(ClassLoader $loader)
    {
        return \Closure::bind(function () use ($loader) {
            $loader->prefixLengthsPsr4 = ComposerStaticInit6cde9c56a62cce11c64af3aebd873be7::$prefixLengthsPsr4;
            $loader->prefixDirsPsr4 = ComposerStaticInit6cde9c56a62cce11c64af3aebd873be7::$prefixDirsPsr4;
            $loader->classMap = ComposerStaticInit6cde9c56a62cce11c64af3aebd873be7::$classMap;

        }, null, ClassLoader::class);
    }
}
