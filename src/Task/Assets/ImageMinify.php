<?php

namespace Robo\Task\Assets;

use Robo\Result;
use Robo\Exception\TaskException;
use Robo\Task\BaseTask;
use Robo\Task\Base\Exec;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Filesystem\Filesystem as sfFilesystem;

/**
 * Minifies images. When the required minifier is not installed on the system
 * the task will try to download it from the [imagemin](https://github.com/imagemin) repository.
 * It might be necessary to adjust the command generation and the download process for a minifier.
 *
 * When the task is run without any specified minifier it will compress the images
 * based on the extension.
 *
 * ```php
 * $this->taskImageMinify('assets/images/*')
 *     ->to('dist/images/')
 *     ->run();
 * ```
 *
 * This will use the following minifiers:
 *
 * - PNG: optipng
 * - GIF: gifsicle
 * - JPG, JPEG: jpegtran
 * - SVG: svgo
 *
 * When the minifier is specified the task will use that for all the input files. In that case
 * it is useful to filter the files with the extension:
 *
 * ```php
 * $this->taskImageMinify('assets/images/*.png')
 *     ->to('dist/images/')
 *     ->minifier('pngcrush');
 *     ->run();
 * ```
 *
 * The task supports the following minifiers:
 *
 * - optipng
 * - pngquant
 * - advpng
 * - pngout
 * - zopflipng
 * - pngcrush
 * - gifsicle
 * - jpegoptim
 * - jpeg-recompress
 * - jpegtran
 * - svgo (only minification, no downloading)
 *
 * You can also specifiy extra options for the minifiers:
 *
 * ```php
 * $this->taskImageMinify('assets/images/*.jpg')
 *     ->to('dist/images/')
 *     ->minifier('jpegtran', ['-progressive' => null, '-copy' => 'none'])
 *     ->run();
 * ```
 *
 * This will execute as:
 * `jpegtran -copy none -progressive -optimize -outfile "dist/images/test.jpg" "/var/www/test/assets/images/test.jpg"`
 */
class ImageMinify extends BaseTask
{
    /**
     * Destination directory for the minified images.
     *
     * @var string
     */
    protected $to;

    /**
     * the base path of the original files so we can derive the path for the target files automatically.
     * @var string
     */
    protected $basePath = null;

    /**
     * Array of the source files.
     *
     * @var array
     */
    protected $dirs = [];

    /**
     * Symfony 2 filesystem.
     *
     * @var sfFilesystem
     */
    protected $fs;

    /**
     * Target directory for the downloaded binary executables.
     *
     * @var string
     */
    protected $executableTargetDir;

    /**
     * Array for the downloaded binary executables.
     *
     * @var array
     */
    protected $executablePaths = [];

    /**
     * Array for the individual results of all the files.
     *
     * @var array
     */
    protected $results = [];

    /**
     * Default minifier to use.
     *
     * @var string
     */
    protected $minifier;

    /**
     * Array for minifier options.
     *
     * @var array
     */
    protected $minifierOptions = [];

    /**
     * Supported minifiers.
     *
     * @var array
     */
    protected $minifiers = [
        // Default 4
        'optipng',
        'gifsicle',
        'jpegtran',
        'svgo',
        // PNG
        'pngquant',
        'advpng',
        'pngout',
        'zopflipng',
        'pngcrush',
        // JPG
        'jpegoptim',
        'jpeg-recompress',
    ];

    /**
     * Binary repositories of Imagemin.
     *
     * @link https://github.com/imagemin
     *
     * @var string[]
     */
    protected $imageminRepos = [
        // PNG
        'optipng' => 'https://github.com/imagemin/optipng-bin',
        'pngquant' => 'https://github.com/imagemin/pngquant-bin',
        'advpng' => 'https://github.com/imagemin/advpng-bin',
        'pngout' => 'https://github.com/imagemin/pngout-bin',
        'zopflipng' => 'https://github.com/imagemin/zopflipng-bin',
        'pngcrush' => 'https://github.com/imagemin/pngcrush-bin',
        // Gif
        'gifsicle' => 'https://github.com/imagemin/gifsicle-bin',
        // JPG
        'jpegtran' => 'https://github.com/imagemin/jpegtran-bin',
        'jpegoptim' => 'https://github.com/imagemin/jpegoptim-bin',
        'cjpeg' => 'https://github.com/imagemin/mozjpeg-bin', // note: we do not support this minifier because it creates JPG from non-JPG files
        'jpeg-recompress' => 'https://github.com/imagemin/jpeg-recompress-bin',
        // WebP
        'cwebp' => 'https://github.com/imagemin/cwebp-bin', // note: we do not support this minifier because it creates WebP from non-WebP files
    ];

    /**
     * @param string|string[] $dirs
     */
    public function __construct($dirs)
    {
        is_array($dirs)
            ? $this->dirs = $dirs
            : $this->dirs[] = $dirs;

        $this->fs = new sfFilesystem();

        // guess the best path for the executables based on __DIR__
        if (($pos = strpos(__DIR__, 'consolidation'.DIRECTORY_SEPARATOR.'robo')) !== false) {
            // the executables should be stored in vendor/bin
            $this->executableTargetDir = substr(__DIR__, 0, $pos) . 'bin';
        } else {
            // store is right here. Might happen during development time if we use a symlink.
            // then there is no /vendor/bin folder.
            $this->executableTargetDir = __DIR__;
        }

        // check if the executables are already available
        foreach ($this->imageminRepos as $exec => $url) {
            $path = $this->executableTargetDir . '/' . $exec;
            // if this is Windows add a .exe extension
            if ($this->isWindows()) {
                $path .= '.exe';
            }
            if (is_file($path)) {
                $this->executablePaths[$exec] = $path;
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function run()
    {
        // find the files
        $files = $this->findFiles($this->dirs);

        // minify the files
        $result = $this->minify($files);
        // check if there was an error
        if ($result instanceof Result) {
            return $result;
        }

        $amount = (count($files) == 1 ? 'image' : 'images');
        $message = "Minified {filecount} out of {filetotal} $amount into {destination}";
        $context = ['filecount' => count($this->results['success']), 'filetotal' => count($files), 'destination' => $this->to];

        if (count($this->results['success']) == count($files)) {
            $this->printTaskSuccess($message, $context);

            return Result::success($this, $message, $context);
        } else {
            return Result::error($this, $message, $context);
        }
    }

    /**
     * Sets the target directory where the files will be copied to.
     *
     * @param string $target
     *
     * @return $this
     */
    public function to($target)
    {
        $this->to = rtrim($target, '/');

        return $this;
    }

    /**
     * Set the base path. This is used to create the path for target images automatically
     *
     * dir; foo/bar/**\/*.jpg
     * basepath: foo/bar
     * to: /dist/images
     *
     * possible files:
     * foo/bar/folder1/1.jpg => /dist/images/folder1/1.jpg
     *
     * @param string $path
     * @return $this
     */
    public function basePath($path) {
        $this->basePath = rtrim($path, '/');
        return $this;
    }

    /**
     * Sets the minifier.
     *
     * @param string $minifier
     * @param array  $options
     *
     * @return $this
     */
    public function minifier($minifier, array $options = [])
    {
        $this->minifier = $minifier;
        $this->minifierOptions = array_merge($this->minifierOptions, $options);

        return $this;
    }

    /**
     * @param string[] $dirs
     *
     * @return array|\Robo\Result
     *
     * @throws \Robo\Exception\TaskException
     */
    protected function findFiles($dirs)
    {
        $files = array();

        // find the files
        foreach ($dirs as $k => $v) {
            // reset finder
            $finder = new Finder();

            $dir = $k;
            $to = $v;
            // check if target was given with the to() method instead of key/value pairs
            if (is_int($k)) {
                $dir = $v;
                if (isset($this->to)) {
                    $to = $this->to;
                } else {
                    throw new TaskException($this, 'target directory is not defined');
                }
            }

            try {
                $finder->files()->in($dir);
            } catch (\InvalidArgumentException $e) {
                // if finder cannot handle it, try with in()->name()
                if (strpos($dir, '/') === false) {
                    $dir = './' . $dir;
                }
                $parts = explode('/', $dir);
                $new_dir = implode('/', array_slice($parts, 0, -1));
                try {
                    $finder->files()->in($new_dir)->name(array_pop($parts));
                } catch (\InvalidArgumentException $e) {
                    return Result::fromException($this, $e);
                }
            }

            foreach ($finder as $file) {
                // store the absolute path as key and target as value in the files array

                $sourceFile = $file->getRealPath();
                $targetDirectory = $to;

                if ($this->basePath) {
                    $targetDirectory = realpath($to) . dirname(substr($sourceFile, strpos($sourceFile, $this->basePath) + strlen($this->basePath)));
                }

                $files[$file->getRealpath()] = $this->getTarget($sourceFile, $targetDirectory);
            }
            $fileNoun = count($finder) == 1 ? ' file' : ' files';
            $this->printTaskInfo("Found {filecount} $fileNoun in {dir}", ['filecount' => count($finder), 'dir' => $dir]);
        }

        return $files;
    }

    /**
     * @param string $file
     * @param string $to
     *
     * @return string
     */
    protected function getTarget($file, $to)
    {
        $target = $to . DIRECTORY_SEPARATOR . basename($file);

        return $target;
    }

    /**
     * @param string[] $files
     *
     * @return \Robo\Result
     */
    protected function minify($files)
    {
        // store the individual results into the results array
        $this->results = [
            'success' => [],
            'error' => [],
        ];

        // loop through the files
        foreach ($files as $from => $to) {
            $minifier = '';

            if (!isset($this->minifier)) {
                // check filetype based on the extension
                $extension = strtolower(pathinfo($from, PATHINFO_EXTENSION));

                // set the default minifiers based on the extension
                switch ($extension) {
                    case 'png':
                        $minifier = 'optipng';
                        break;
                    case 'jpg':
                    case 'jpeg':
                        $minifier = 'jpegtran';
                        break;
                    case 'gif':
                        $minifier = 'gifsicle';
                        break;
                    case 'svg':
                        $minifier = 'svgo';
                        break;
                }
            } else {
                if (!in_array($this->minifier, $this->minifiers, true)
                    && !is_callable(strtr($this->minifier, '-', '_'))
                ) {
                    $message = sprintf('Invalid minifier %s!', $this->minifier);

                    return Result::error($this, $message);
                }
                $minifier = $this->minifier;
            }

            // Convert minifier name to camelCase (e.g. jpeg-recompress)
            $funcMinifier = $this->camelCase($minifier);

            // create target directory if it does not exist.
            $targetDir = dirname($to);
            if (!is_dir($targetDir)) {
                mkdir($targetDir, 0777, true);
            }

            // call the minifier method which prepares the command
            if (is_callable($funcMinifier)) {
                $command = call_user_func($funcMinifier, $from, $to, $this->minifierOptions);
            } elseif (method_exists($this, $funcMinifier)) {
                $command = $this->{$funcMinifier}($from, $to);
            } else {
                $message = sprintf('Minifier method <info>%s</info> cannot be found!', $funcMinifier);

                return Result::error($this, $message);
            }

            // launch the command
            $this->printTaskInfo('Minifying {filepath} with {minifier}', ['filepath' => $from, 'minifier' => $minifier]);
            $result = $this->executeCommand($command);

            // check the success of the conversion
            if ($result->getExitCode() !== 0) {
                $this->results['error'][] = $from;
            } else {
                $this->results['success'][] = $from;
            }
        }
    }


    protected function isWindows() {
        return PHP_OS ==  'WINNT';
    }

    protected function isLinux() {
        return PHP_OS == 'Linux';
    }
    /**
     * @return string
     */
    protected function getOS()
    {
        $os = php_uname('s');
        $os .= '/' . php_uname('m');
        // replace x86_64 to x64, because the imagemin repo uses that
        $os = str_replace('x86_64', 'x64', $os);
        // replace i386, i686, etc to x86, because of imagemin
        $os = preg_replace('/i[0-9]86/', 'x86', $os);
        // turn info to lowercase, because of imagemin
        $os = strtolower($os);

        if ($this->isWindows()) {
            $os = 'win';
        }

        return $os;
    }

    /**
     * @param string $command
     * @return \Robo\Result
     */
    protected function executeCommand($command)
    {
        // insert the options into the command
        $a = explode(' ', $command);
        $executable = array_shift($a);
        foreach ($this->minifierOptions as $key => $value) {
            // first prepend the value
            if (!empty($value)) {
                array_unshift($a, $value);
            }
            // then add the key
            if (!is_numeric($key)) {
                array_unshift($a, $key);
            }
        }

        $this->prepareExecution($executable);

        // check if the executable can be replaced with the downloaded one
        if (array_key_exists($executable, $this->executablePaths)) {
            $executable = $this->executablePaths[$executable];
        }
        array_unshift($a, $executable);
        $command = implode(' ', $a);

        // execute the command
        $exec = new Exec($command);

        return $exec->inflect($this)->printOutput(false)->run();
    }

    /**
     * Check if the command for an optimizer is available.
     *
     * @param String $executable
     */
    protected function prepareExecution($executable): void {
        // we checked for the executable file in the constructor already
        if (array_key_exists($executable, $this->executablePaths)) {
            return;
        }

        // no additional downloaded files exist. Is the command avaiable?
        if ($this->isCommandExisting($executable)) {
            return;
        }

        $this->installFromImagemin($executable);
    }

    /**
     * @param string $executable
     *
     * @return \Robo\Result
     */
    protected function installFromImagemin($executable)
    {
        // check if there is an url defined for the executable
        if (!array_key_exists($executable, $this->imageminRepos)) {
            $message = sprintf('The executable %s cannot be found in the defined imagemin repositories', $executable);

            return Result::error($this, $message);
        }
        $this->printTaskInfo('Downloading the {executable} executable from the imagemin repository', ['executable' => $executable]);

        $os = $this->getOS();

        $isDownloadHandled = false;

        // check if there is a minimizer specific download function and execute it
        $downloadFunctionName =   $this->camelCase('download_'.$executable);
        if (method_exists($this, $downloadFunctionName)) {
            $isDownloadHandled = $this->{$downloadFunctionName}();
        }

        // nothing special found, so try to guess the download urls.
        if (!$isDownloadHandled) {
            $url = $this->imageminRepos[$executable] . '/blob/master/vendor/' . $os . '/' . $executable . '?raw=true';
            if ($this->isWindows()) {
                // if it is win, add a .exe extension
                $url = $this->imageminRepos[$executable] . '/blob/master/vendor/' . $os . '/' . $executable . '.exe?raw=true';
            }
            $data = @file_get_contents($url, false, null);
            if ($data === false) {
                // there is something wrong with the url, try it without the version info
                $url = preg_replace('/x[68][64]\//', '', $url);
                $data = @file_get_contents($url, false, null);
                if ($data === false) {
                    // there is still something wrong with the url if it is win, try with win32
                    if ($this->isWindows()) {
                        $url = preg_replace('/win\//', 'win32/', $url);
                        $data = @file_get_contents($url, false, null);
                        if ($data === false) {
                            // there is nothing more we can do
                            $message = sprintf('Could not download the executable <info>%s</info>', $executable);

                            return Result::error($this, $message);
                        }
                    }
                    // if it is not windows there is nothing we can do
                    $message = sprintf('Could not download the executable <info>%s</info>', $executable);

                    return Result::error($this, $message);
                }
            }

            // save the executable into the target dir
            $path = $this->executableTargetDir . '/' . $executable;
            if ($this->isWindows()) {
                // if it is win, add a .exe extension
                $path = $this->executableTargetDir . '/' . $executable . '.exe';
            }

            if ($this->storeFile($data, $path) === false) {
                $message = sprintf('Could not copy the executable <info>%s</info> to %s', $executable, $path);

                return Result::error($this, $message);
            }
        }

        // if everything successful, store the executable path
        $this->executablePaths[$executable] = $this->executableTargetDir . '/' . $executable;
        // if it is win, add a .exe extension
        if ($this->isWindows()) {
            $this->executablePaths[$executable] .= '.exe';
        }

        $message = sprintf('Executable <info>%s</info> successfully downloaded', $executable);
        $this->printTaskInfo($message);
        return Result::success($this, $message);
    }

    private function storeFile($data, $path) {

        // check if target directory exists
        if (!is_dir($this->executableTargetDir)) {
            mkdir($this->executableTargetDir);
        }

        $result = file_put_contents($path, $data);

        if ($result === false) {
            return false;
        }
        // set the binary to executable
        chmod($path, 0755);

        return true;
    }

    /**
     * Downloads files from URLs and store them in the executableTargetDir
     *
     * @param $filenameUrlMap a map containing the filename and the url ['foo.exe'=>'http://xxx..']
     * @return bool
     */
    private function downloadFilesFromUrls($filenameUrlMap) {
        foreach($filenameUrlMap as $filename => $url) {

            $data = @file_get_contents($url);
            if ($data === false) {
                return false;
            }
            if ($this->storeFile($data, $this->executableTargetDir . DIRECTORY_SEPARATOR . $filename) === false) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param string $from
     * @param string $to
     *
     * @return string
     */
    protected function optipng($from, $to)
    {
        $command = sprintf('optipng -quiet -out "%s" -- "%s"', $to, $from);
        if ($from != $to && is_file($to)) {
            // earlier versions of optipng do not overwrite the target without a backup
            // http://sourceforge.net/p/optipng/bugs/37/
            unlink($to);
        }

        return $command;
    }

    /**
     * @param string $from
     * @param string $to
     *
     * @return string
     */
    protected function jpegtran($from, $to)
    {
        $command = sprintf('jpegtran -optimize -outfile "%s" "%s"', $to, $from);

        return $command;
    }

    protected function downloadJpegtran() {
        if ($this->isWindows()) {

            $urls = [
                'jpegtran.exe' => $this->imageminRepos['jpegtran'] . '/blob/master/vendor/win/x64/jpegtran.exe?raw=true',
                'libjpeg-62.dll' => $this->imageminRepos['jpegtran'] . '/blob/master/vendor/win/x64/libjpeg-62.dll?raw=true'
            ];

            return $this->downloadFilesFromUrls($urls);
        }

        return false;
    }

    /**
     * @param string $from
     * @param string $to
     *
     * @return string
     */
    protected function gifsicle($from, $to)
    {
        $command = sprintf('gifsicle -o "%s" "%s"', $to, $from);

        if ($this->isLinux()) {
            if (!file_exists('./node_modules/.bin/gifsicle')) {
                $this->prepareExecution('gifsicle');
            }
            $command = sprintf(realpath ('./node_modules/.bin/gifsicle'),' -o "%s" "%s"', $to, $from);
        }

        return $command;
    }

    protected function downloadGifsicle() {
        if ($this->isWindows()) {

            $urls = [
                'gifsicle.exe' => $this->imageminRepos['gifsicle'] . '/blob/master/vendor/win/x64/gifsicle.exe?raw=true'
            ];

            return $this->downloadFilesFromUrls($urls);
        }

        if ($this->isLinux()) {
            $command = 'npm install gifsicle';
            $exec = new Exec($command);
            $result = $exec->inflect($this)->printOutput(true)->run();
            if ($result->getExitCode() == 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string $from
     * @param string $to
     *
     * @return string
     */
    protected function svgo($from, $to)
    {
        $command = sprintf('svgo "%s" "%s"', $from, $to);

        return $command;
    }

    /**
     * @param string $from
     * @param string $to
     *
     * @return string
     */
    protected function pngquant($from, $to)
    {
        $command = sprintf('pngquant --force --output "%s" "%s"', $to, $from);

        return $command;
    }

    /**
     * @param string $from
     * @param string $to
     *
     * @return string
     */
    protected function advpng($from, $to)
    {
        // advpng does not have any output parameters, copy the file and then compress the copy
        $command = sprintf('advpng --recompress --quiet "%s"', $to);
        $this->fs->copy($from, $to, true);

        return $command;
    }

    /**
     * @param string $from
     * @param string $to
     *
     * @return string
     */
    protected function pngout($from, $to)
    {
        $command = sprintf('pngout -y -q "%s" "%s"', $from, $to);

        return $command;
    }

    /**
     * @param string $from
     * @param string $to
     *
     * @return string
     */
    protected function zopflipng($from, $to)
    {
        $command = sprintf('zopflipng -y "%s" "%s"', $from, $to);

        return $command;
    }

    /**
     * @param string $from
     * @param string $to
     *
     * @return string
     */
    protected function pngcrush($from, $to)
    {
        $command = sprintf('pngcrush -q -ow "%s" "%s"', $from, $to);

        return $command;
    }

    /**
     * @param string $from
     * @param string $to
     *
     * @return string
     */
    protected function jpegoptim($from, $to)
    {
        // jpegoptim only takes the destination directory as an argument
        $command = sprintf('jpegoptim --quiet -o --dest "%s" "%s"', dirname($to), $from);

        return $command;
    }

    /**
     * @param string $from
     * @param string $to
     *
     * @return string
     */
    protected function jpegRecompress($from, $to)
    {
        $command = sprintf('jpeg-recompress --quiet "%s" "%s"', $from, $to);

        return $command;
    }

    /**
     * @param string $text
     *
     * @return string
     */
    public static function camelCase($text)
    {
        // non-alpha and non-numeric characters become spaces
        $text = preg_replace('/[^a-z0-9]+/i', ' ', $text);
        $text = trim($text);
        // uppercase the first character of each word
        $text = ucwords($text);
        $text = str_replace(" ", "", $text);
        $text = lcfirst($text);

        return $text;
    }

    /**
     * checks if a command line command exist.
     */
    private function isCommandExisting ($command) {
        $whereIsCommand = ($this->isWindows()) ? 'where' : 'which';

        $process = proc_open(
            "$whereIsCommand $command",
            array(
                0 => array("pipe", "r"), //STDIN
                1 => array("pipe", "w"), //STDOUT
                2 => array("pipe", "w"), //STDERR
            ),
            $pipes
        );
        if ($process !== false) {
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);

            return $stdout != '';
        }

        return false;
    }
}
