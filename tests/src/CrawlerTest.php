<?php

namespace Arachnid;

use Arachnid\Link;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

use Symfony\Component\Panther\PantherTestCaseTrait;
use Symfony\Component\Panther\Client as PantherClient;
use PHPUnit\Framework\TestCase;

class CrawlerTest extends TestCase
{
    public static $baseTestUrl = 'http://127.0.0.1:9001';
    
    use PantherTestCaseTrait;
    
    protected function setUp() {  
        self::startWebServer(__DIR__.'/../data', '127.0.0.1', 9001);
        parent::setUp();
    }
    
    protected function tearDown() {
        static::stopWebServer();
        parent::tearDown();
    }
    
    /**
     * test crawling remote file, ex. google.com
     */
    public function testRemoteFile()
    {
        $url = 'https://www.google.com/';
        $crawler = new Crawler($url, 1);
        $crawler->traverse();
        $links = $crawler->getLinks();
        $this->assertEquals(get_class($crawler->getScrapClient()), \Goutte\Client::class);

        /*@var $link Link */
        $mainLink = $links[$url];
        $this->assertInstanceOf(Link::class, $mainLink);
        $this->assertEquals($mainLink->getStatusCode(), 200, $url.' shall be 200 ok');
        $this->assertGreaterThan(3, count($links));
    }
        
    /**
     * test scrapping chrome scrapping client
     */
    public function testSetScrapClient()
    {
        $crawler = new Crawler('index', 1);
        $crawler->setScrapClient(PantherClient::createChromeClient());
        $this->assertInstanceOf(PantherClient::class,$crawler->getScrapClient());
    }

    /**
     * test crawling non-existent page
     */
    public function testFileNotExist()
    {
        $crawler = new Crawler(self::$baseTestUrl.'/test.html', 1); //non existing url/path

        $crawler->traverse();
        $links = $crawler->getLinks();        
        $this->assertArrayHasKey(self::$baseTestUrl.'/test.html', $links);
        
        $mainLink = $links[self::$baseTestUrl.'/test.html'];
        $this->assertInstanceOf(Link::class, $mainLink,'entry must be instance of Link class');
        $this->assertEquals($mainLink->getStatusCode(), 404,'status code must be 404, given: '.$mainLink->getStatusCode());
    }

    /**
     * test crawling one level pages
     */
    public function testScrapperClient1Level()
    {
        $filePath = self::$baseTestUrl.'/index.html';
        $crawler = new Crawler($filePath, 1); //non existing url/path        
        $crawler->traverse();
     
        $links = $crawler->getLinks();
     
        $this->assertEquals($links[$filePath]->getStatusCode(), 200, $filePath.' shall be 200 ok');                
        $this->assertEquals(7, count($links)); //7 after normalizing fragments
    }

        /**
         * test crawling two levels pages
         */
    public function testScrapperClient2Level()
    {
        $filePath = self::$baseTestUrl.'/index.html';
        $crawler = new Crawler($filePath, 2); 
        $crawler->traverse();
        $links = $crawler->getLinks();
        
        $this->assertEquals($links[$filePath]->getStatusCode(), 200);
        $this->assertCount(12, $links);
    }

    /**
     * test crawling three levels pages
     */
    public function testScrapperClient3Level()
    {
        $filePath = self::$baseTestUrl.'/index.html';
        $crawler = new Crawler($filePath, 6);
                
        $logger = new Logger('crawling logger');
        $logger->pushHandler(new StreamHandler(sys_get_temp_dir().'/crawler.log'));
        $crawler->setLogger($logger);
        $crawler->traverse();

        $links = $crawler->getLinks();

        $this->assertEquals($links[$filePath]->getStatusCode(), 200);
        $this->greaterThan(8, count($links));
        
        $this->assertArrayHasKey('http://facebook.com', $links);
        $this->assertArrayHasKey(self::$baseTestUrl.'/sub_dir/level2-3.html', $links);                
        
        $this->assertEquals(200, $links[self::$baseTestUrl.'/sub_dir/level2-3.html']->getStatusCode());
        $this->assertEquals(200, $links[self::$baseTestUrl.'/sub_dir/level3-1.html']->getStatusCode());
        $this->assertEquals(200, $links[self::$baseTestUrl.'/sub_dir/level4-1.html']->getStatusCode());        
        $this->assertEquals(200, $links[self::$baseTestUrl.'/sub_dir/level5-1.html']->getStatusCode());
    }

        /**
         * test broken links
         */
    public function testBrokenLink()
    {
        $filePath = self::$baseTestUrl.'/sub_dir/level1-3.html2';
        $crawler = new Crawler($filePath, 2); //non existing url/path        
        $crawler->traverse();

        $links = $crawler->getLinks();                
        $this->assertEquals($links[$filePath]->getStatusCode(), 404);
        
        $collection = new LinksCollection($links);
        $this->assertEquals($collection->getBrokenLinks()->count(),1);
    }

 
        /**
         * test filtering links callback
         */
    public function testfilterCallback()
    {
        $logger = new Logger('crawler logger');
        $logger->pushHandler(new StreamHandler(sys_get_temp_dir().'/crawler.log'));
            
        $client = new Crawler('https://github.com/blog/', 2);
            
        $links = $client->setLogger($logger)
                    ->filterLinks(function ($link) {
                        return (bool)preg_match('/.*\/blog.*$/u', $link); //crawling only blog links
                    })
                    ->traverse()
                    ->getLinks();
            
        foreach ($links as $uri => $linkObj) {
            /*@var $linkObj Link*/
            $this->assertRegExp('/.*\/blog.*$/u', $linkObj->getAbsoluteUrl());
        }
        
        $testHandler = new \Monolog\Handler\TestHandler();
        $logger2 = new Logger('crawler logger');
        $logger2->pushHandler($testHandler);
        
        
        $filePath = self::$baseTestUrl.'/index.html';
        $crawler2 = new Crawler($filePath, 3);
        $crawler2
                ->setLogger($logger2)
                ->filterLinks(function($link){
                    return strpos($link,'level1-2.html')===false;
                })
                ->traverse();
        
        $this->assertTrue($testHandler->hasRecordThatMatches(
                '/.*(skipping.+level1-2.html).*/',
                200
        ));
        
    }
        
        /**
         * test filtering links callback 2
         */
    public function testfilterCallback2()
    {
        $logger = new Logger('crawler logger');
        $logger->pushHandler(new StreamHandler(sys_get_temp_dir().'/crawler.log'));
        
        $client = new Crawler(self::$baseTestUrl.'/filter1.html', 4);
            
        $client->setLogger($logger)
           ->filterLinks(function ($link) {                
                 return strpos((string)$link,'dont-visit')===false; // prevent any link containing dont-visit in url
           })->traverse();
        $links = $client->getLinks();
        
        $this->assertGreaterThan(2,count($links));
        foreach ($links as $linkObj) {
            /*@var $linkObj Link*/
            $this->assertNotRegExp('/.*dont\-visit.*/U', $linkObj->getAbsoluteUrl());
        }
    }
        
    /**
     * test crawling one level pages
     */
    public function testMetaInfo()
    {
        $filePath = self::$baseTestUrl.'/index.html';
        $crawler = new Crawler($filePath,1);
        $crawler->traverse();
        $links = $crawler->getLinks();
                
        /*@var $mainLink Link*/
        $mainLink = $links[$filePath];
        $this->assertEquals($mainLink->getStatusCode(), 200, $filePath.' shall be 200 ok');
        
        $this->assertEquals($mainLink->getMetaInfo('title'), 'Main Page');
        $this->assertEquals($mainLink->getMetaInfo('meta_description'), 'meta description for main page');
        $this->assertEquals($mainLink->getMetaInfo('meta_keywords'), 'keywords1, keywords2');
    }
    
    /**
     * testing depth functionality
     */
    public function testFilterByDepth()
    {
        $filePath = self::$baseTestUrl.'/index.html';
        $crawler = new Crawler($filePath, 3);
        $crawler->traverse();
        $links = $crawler->getLinks();
        
        $collection = new LinksCollection($links);        
        $depth1Links = $collection->filterByDepth(1);        
        $depth2Links = $collection->filterByDepth(2);
        
        $this->assertEquals(6, $depth1Links->count());
        //ignoring already traversed links in previous levels
        $this->assertEquals(5, $depth2Links->count()); 
    }
    
    public function testGroupLinksByDepth(){
        
        $filePath = self::$baseTestUrl.'/index.html';
        $crawler = new Crawler($filePath, 6);
        $crawler->traverse();
        $links = $crawler->getLinks();
        
        $collection = new LinksCollection($links);        
        $linksByDepth = $collection->groupLinksByDepth();
        
        $this->assertArrayHasKey(5, $linksByDepth);
    }
        
    /**
    * test setting guzzle options
    */
    public function testConfigureGuzzleOptions()
    {
            
        $options = array(
        'curl' => array(
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_SSL_VERIFYPEER => false,
        ),
        'timeout' => 30,
        'connect_timeout' => 30,
        );
            
        $crawler = new Crawler('http://github.com', 2);
        $crawler->setCrawlerOptions($options);
            
        /*@var $guzzleClient \GuzzleHttp\Client */
        $guzzleClient = $crawler->getScrapClient()->getClient();
            
        $this->assertEquals(30, $guzzleClient->getConfig('timeout'));
        $this->assertEquals(30, $guzzleClient->getConfig('connect_timeout'));
        $this->assertEquals(false, $guzzleClient->getConfig('curl')[CURLOPT_SSL_VERIFYHOST]);
            
        $crawler2 = new Crawler('http://github.com', 2, array(
            'auth' => array('username', 'password'),
        ));
        /*@var $guzzleClient \GuzzleHttp\Client */
        $guzzleClient2 = $crawler2->getScrapClient()->getClient();
            
        $actualConfigs = $guzzleClient2->getConfig();
        $this->assertArrayHasKey('auth', $actualConfigs);
    }
        
    public function testNotVisitUnCrawlableUrl()
    {

        $testHandler = new \Monolog\Handler\TestHandler();
        $logger = new Logger('test logger');
        $logger->pushHandler($testHandler);
        
        $filePath = '/index.html';
        
        $crawler = new Crawler(self::$baseTestUrl.$filePath, 2);
        $crawler->setLogger($logger);
        $crawler->traverse();
        
        $this->assertFalse(
            $testHandler->hasRecordThatMatches(
                '/.*(crawling\stel\:).*/',
                200
            )
        );       
        $this->assertEquals(
            1,
            $testHandler->hasRecordThatMatches(
                '/.*(skipping\s"tel\:).*/',
                200
            )
        );
    }
    
    public function testGroupLinksGroupedBySource(){
               
        $filePath = '/index.html';
        
        $crawler = new Crawler(self::$baseTestUrl.$filePath, 3);
        $crawler->traverse();
        $links = $crawler->getLinks();
        
        $collection = new LinksCollection($links);        
        $linksBySource = $collection->groupLinksGroupedBySource();
        
        $this->assertArrayHasKey(self::$baseTestUrl.'/sub_dir/level3-1.html', $linksBySource);
        $this->assertArrayHasKey(self::$baseTestUrl.'/level1-1.html', $linksBySource);
        
        $this->assertEquals(1,count($linksBySource[self::$baseTestUrl.'/sub_dir/level3-1.html']));
        $this->assertEquals(2,count($linksBySource[self::$baseTestUrl.'/level1-1.html']));
    }

    
    public function testNotResolvedUrl(){
        $nonFoundUrl = 'http://non-existing-url.com';
        $crawler = new Crawler($nonFoundUrl);
        $links = $crawler->traverse()
                ->getLinks();
        
        $this->assertEquals(500,$links[$nonFoundUrl]->getStatusCode());
    }

}
