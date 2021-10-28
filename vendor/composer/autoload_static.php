<?php

// autoload_static.php @generated by Composer

namespace Composer\Autoload;

class ComposerStaticInit44dc18531eb1e0cfd09776080bf2c4c0
{
    public static $prefixLengthsPsr4 = array (
        'H' => 
        array (
            'Hdden\\ParsedProcessor\\' => 22,
        ),
        'D' => 
        array (
            'DiDom\\' => 6,
        ),
    );

    public static $prefixDirsPsr4 = array (
        'Hdden\\ParsedProcessor\\' => 
        array (
            0 => __DIR__ . '/../..' . '/src',
        ),
        'DiDom\\' => 
        array (
            0 => __DIR__ . '/..' . '/imangazaliev/didom/src/DiDom',
        ),
    );

    public static $classMap = array (
        'Composer\\InstalledVersions' => __DIR__ . '/..' . '/composer/InstalledVersions.php',
    );

    public static function getInitializer(ClassLoader $loader)
    {
        return \Closure::bind(function () use ($loader) {
            $loader->prefixLengthsPsr4 = ComposerStaticInit44dc18531eb1e0cfd09776080bf2c4c0::$prefixLengthsPsr4;
            $loader->prefixDirsPsr4 = ComposerStaticInit44dc18531eb1e0cfd09776080bf2c4c0::$prefixDirsPsr4;
            $loader->classMap = ComposerStaticInit44dc18531eb1e0cfd09776080bf2c4c0::$classMap;

        }, null, ClassLoader::class);
    }
}
