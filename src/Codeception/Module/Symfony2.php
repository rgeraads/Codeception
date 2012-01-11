<?php
namespace Codeception\Module;

/**
 * This module uses Symfony2 Crawler and HttpKernel to emulate requests and get response.
 *
 * It implements common Framework interface.
 *
 * ## Config
 *
 * * app_path: 'app' - specify custom path to your app dir, where bootstrap cache and kernel interface is located.
 *
 * ## Public Properties
 *
 * * kernel - HttpKernel instance
 * * client - current Crawler instance
 *
 */

use Codeception\Util\Connector\Symfony2 as Connector;
use Symfony\Component\Finder\Finder;

class Symfony2 extends \Codeception\Util\Framework
{
    /**
     * @var \Symfony\Component\HttpKernel\Kernel
     */
    public $kernel;

    public $config = array('app_path' => 'app');
    /**
     * @var
     */
    protected $kernelClass;

    protected $clientClass = '\Symfony\Component\HttpKernel\Client';


    public function _initialize() {
        $cache = getcwd().DIRECTORY_SEPARATOR.$this->config['app_path'].DIRECTORY_SEPARATOR.'bootstrap.php.cache';
        if (!file_exists($cache)) throw new \RuntimeException('Symfony2 bootstrap file not found in '.$cache);
        require_once $cache;
        $this->kernelClass = $this->getKernelClass();
        $this->kernel = new $this->kernelClass('test', true);
        $this->kernel->boot();

        $dispatcher = $this->kernel->getContainer()->get('event_dispatcher');
        $dispatcher->addListener('kernel.exception', function ($event) {
            throw $event->getException();
        });

    }
    
    public function _before(\Codeception\TestCase $test) {
        $this->client = new $this->clientClass($this->kernel);
        $this->client->followRedirects(true);
    }

    public function _after(\Codeception\TestCase $test) {
        $this->kernel->shutdown();
        unset($this->client);
    }

    /**
     * Attempts to guess the kernel location.
     *
     * When the Kernel is located, the file is required.
     *
     * @return string The Kernel class name
     */
    protected function getKernelClass()
    {
        $finder = new Finder();
        $finder->name('*Kernel.php')->depth('0')->in($this->config['app_path']);
        $results = iterator_to_array($finder);
        if (!count($results)) {
            throw new \RuntimeException('Provide kernel_dir as parameter for Symfony2 module');
        }

        $file = current($results);
        $class = $file->getBasename('.php');

        require_once $file;

        return $class;
    }

    /**
     * Authenticates user for HTTP_AUTH 
     *
     * @param $username
     * @param $password
     */
    public function amHttpAuthenticated($username, $password) {
        $this->client->setServerParameter('PHP_AUTH_USER', $username);
        $this->client->setServerParameter('PHP_AUTH_PW', $password);
    }


    /**
     * Checks if any email were sent by last request
     *
     * @throws \LogicException
     */
    public function seeEmailIsSent() {
        $profile = $this->getProfiler();
        if (!$profile) \PHPUnit_Framework_Assert::fail('Emails can\'t be tested without Profiler');
        if (!$profile->hasCollector('swiftmailer')) \PHPUnit_Framework_Assert::fail('Emails can\'t be tested without SwiftMailer connector');

        \PHPUnit_Framework_Assert::assertGreaterThan(0, $profile->getCollector('swiftmailer')->getMessageCount());
    }



    /**
     * @return \Symfony\Component\HttpKernel\Profiler\Profile
     */
    protected function getProfiler()
    {
        if (!$this->kernel->getContainer()->has('profiler')) return null;
        $profiler = $this->kernel->getContainer()->get('profiler');
        return $profiler->loadProfileFromResponse($this->client->getResponse());
    }

    protected function debugResponse()
    {
        $this->debugSection('Page', $this->client->getHistory()->current()->getUri());
        if ($profile = $this->getProfiler()) {
            if ($profile->hasCollector('security')) {
                if ($profile->getCollector('security')->isAuthenticated()) {
                    $this->debugSection('User', $profile->getCollector('security')->getUser().' ['.implode(',', $profile->getCollector('security')->getRoles()).']');
                } else {
                    $this->debugSection('User', 'Anonymous');
                }
            }
            if ($profile->hasCollector('swiftmailer')) {
                $messages = $profile->getCollector('swiftmailer')->getMessageCount();
                if ($messages) $this->debugSection('Emails',$messages .' sent');
            }
            if ($profile->hasCollector('timer'))    $this->debugSection('Time', $profile->getCollector('timer')->getTime());
            if ($profile->hasCollector('db'))       $this->debugSection('Db', $profile->getCollector('db')->getQueryCount().' queries');
        }
    }
}