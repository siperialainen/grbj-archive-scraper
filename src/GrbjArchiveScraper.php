<?php

namespace siperialainen;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Pool;
use \DOMXPath;
use \DOMDocument;
use \DateTime;

/**
 * Class GrbjArchiveScraper
 * @package siperialainen
 */
class GrbjArchiveScraper
{
  private $baseUri;
  private $startDate;
  private $endDate;
  private $maxResultsPerAuthor;
  private $concurrency;
  private $wait;

  private $queue;
  private $result;

  /**
   * GrbjArchiveScraper constructor.
   */
  function __construct()
  {
    // Initialize with default parameters.
    $this->baseUri = 'http://archive-grbj-2.s3-website-us-west-1.amazonaws.com/';
    $this->concurrency = 2;
    $this->startDate = null;
    $this->endDate = null;
    $this->maxResultsPerAuthor = null;
    $this->wait = null;

    $this->queue = new \SplQueue();
    $this->result = [];
  }

  /**
   * Main method that should be called after all parameters are set, returns authors array.
   *
   * @return array
   */
  public function scrape()
  {
    $domAuthors = $this->getDom($this->baseUri . 'authors.html');

    $featuredAuthorLinks = $this->getNodes(
        $domAuthors,
        './/div[@class=\'featured\']/div[@class=\'records\']/div[@class=\'record\']//div[@class=\'author-info\']/h1[@class=\'headline\']/a[@href]/@href'
    );
    foreach ($featuredAuthorLinks as $featuredAuthorLink) {
      $this->queue->enqueue(
          (object)[
              'url' => $this->baseUri . $featuredAuthorLink->value,
              'type' => 'AuthorPage',
          ]
      );
    }

    $authorLinks = $this->getNodes(
        $domAuthors,
        './/div[@class=\'authors\']/div[@class=\'records author-letter\']//a[@href]/@href'
    );
    foreach ($authorLinks as $authorLink) {
      $this->queue->enqueue(
          (object)[
              'url' => $this->baseUri . $authorLink->value,
              'type' => 'AuthorPage',
          ]
      );
    }

    $this->runQueue();

    return $this->result;
  }

  /**
   * Runs queued requests, calls appropriate methods to scrape the pages.
   */
  private function runQueue()
  {
    while (!$this->queue->isEmpty()) {
      $client = new Client(['timeout' => $this->wait]);

      $requests = [];
      $processed = [];

      for ($i = 0; $i < $this->concurrency; $i++) {
        if (!$this->queue->isEmpty()) {
          $pageUrl = $this->queue->dequeue();
        } else {
          break;
        }

        $processed[] = $pageUrl;
        $requests[] = new Request('GET', $pageUrl->url);
      }

      if (isset($this->wait)) {
        sleep($this->wait);
      }

      $results = Pool::batch($client, $requests);

      foreach ($results as $i => $response) {
        if (method_exists($response, 'getBody')) {
          if ($processed[$i]->type === 'AuthorPage') {
            $this->scrapeAuthorPage($response->getBody(), $processed[$i]->url);
          } else {
            if ($processed[$i]->type === 'ArticlesPage') {
              $this->scrapeArticlesPage(
                  $response->getBody(),
                  $processed[$i]->url,
                  $processed[$i]->authorId
              );
            }
          }
        }
      }
    }
  }

  /**
   * Gets Dom tree object by url.
   *
   * @param null $url
   * @param null $body
   * @return DOMDocument
   */
  private function getDom($url = null, $body = null)
  {
    if ($url !== null) {
      $client = new Client([
          'base_uri' => $url,
          'timeout' => $this->wait
      ]);

      $response = $client->request('GET', $url);
      $body = $response->getBody();
    }

    $dom = new DOMDocument;

    libxml_use_internal_errors(true);
    $dom->loadHTML(mb_convert_encoding($body, 'HTML-ENTITIES', 'UTF-8'));
    libxml_clear_errors();

    return $dom;
  }


  /**
   * Scrapes author's page, stores results in $this->result array.
   *
   * @param $authorPage
   * @param $authorUrl
   */
  private function scrapeAuthorPage($authorPage, $authorUrl)
  {
    $authorDom = $this->getDom(null, $authorPage);

    $authorInfoNode = $this->getNode($authorDom, './/div[@class=\'author-info\']');

    $authorNameNode = $this->getNode($authorDom, './h1[@class=\'headline\']//a', $authorInfoNode);
    $authorName = $authorNameNode->nodeValue;
    $authorBioNode = $this->getNode($authorDom, './div[@class=\'abstract\']', $authorInfoNode);
    $authorBio = $authorBioNode->nodeValue;

    $authorTwitterNode = $this->getNode(
        $authorDom,
        '//div[@class=\'abstract\']/a[contains(@href,\'://twitter.com/\')]',
        $authorInfoNode
    );
    $authorTwitter = $authorTwitterNode ? $authorTwitterNode->getAttribute('href') : null;

    $authorArticlesLinkNode = $this->getNode(
        $authorDom,
        './div[@class=\'link articles\']//a',
        $authorInfoNode
    );
    $authorArticlesLinkUrl = $this->makeAbsoluteUrl($authorArticlesLinkNode->getAttribute('href'), $authorUrl);

    $author = [
        'authorName' => $authorName,
        'authorBio' => $authorBio,
        'authorTwitter' => $authorTwitter,
        'articles' => [],
    ];

    if (!isset($this->maxResultsPerAuthor) || $this->maxResultsPerAuthor > 0) {
      $this->queue->enqueue(
          (object)[
              'url' => $authorArticlesLinkUrl,
              'type' => 'ArticlesPage',
              'authorId' => count($this->result),
          ]
      );
    }

    $this->result[] = $author;
  }

  /**
   * Scrapes author's articles page, stores results in $this->result array.
   *
   * @param $authorArticlesPage
   * @param $authorArticlesUrl
   * @param $authorId
   */
  private function scrapeArticlesPage($authorArticlesPage, $authorArticlesUrl, $authorId)
  {
    if (
        isset($this->maxResultsPerAuthor) &&
        $this->maxResultsPerAuthor == count($this->result[$authorId]['articles'])
    ) {
      return;
    }

    $authorArticlesPageDom = $this->getDom(null, $authorArticlesPage);
    $recordNodes = $this->getNodes(
        $authorArticlesPageDom,
        './/div[@class=\'records\']//div[@class=\'record clearfix\']'
    );

    foreach ($recordNodes as $recordNode) {
      $articleDateNode = $this->getNode($authorArticlesPageDom, './/div[@class=\'date\']', $recordNode);
      $articleDate = new DateTime($articleDateNode->nodeValue);

      if (
          isset($this->startDate) && $this->startDate > $articleDate ||
          isset($this->endDate) && $this->endDate < $articleDate
      ) {
        continue;
      }

      $articleTitleNode = $this->getNode(
          $authorArticlesPageDom,
          './/h2[@class=\'headline\']//a',
          $recordNode
      );
      $articleTitle = $articleTitleNode->nodeValue;
      $articleUrl = $this->makeAbsoluteUrl($articleTitleNode->getAttribute('href'), $authorArticlesUrl);

      $this->result[$authorId]['articles'][] = [
          'articleTitle' => $articleTitle,
          'articleUrl' => $articleUrl,
          'articleDate' => $articleDate->format('Y-m-d'),
      ];

      if (
          isset($this->maxResultsPerAuthor) &&
          $this->maxResultsPerAuthor == count($this->result[$authorId]['articles'])
      ) {
        foreach ($this->queue as $key => $item) {
          if ($item->type === 'ArticlesPage' && $item->authorId === $authorId) {
            unset($this->queue[$key]);
          }
        }
        return;
      }
    }

    $currentPageNode = $this->getNode($authorArticlesPageDom, './/div[@class=\'pagination\']/em');

    if ($currentPageNode) {
      $currentPage = $currentPageNode->nodeValue;

      if ($currentPage == 1) {
        $lastPageNode = $this->getNode($authorArticlesPageDom, './/div[@class=\'pagination\']/a[last()-1]');

        if ($lastPageNode) {
          $lastPage = $lastPageNode->nodeValue;
          $pageUrl = $this->makeAbsoluteUrl('articles-page=', $authorArticlesUrl);

          for ($i = 2; $i <= $lastPage; $i++) {
            $this->queue->enqueue(
                (object)[
                    'url' => $pageUrl . $i . '.html',
                    'type' => 'ArticlesPage',
                    'authorId' => $authorId,
                ]
            );
          }
        }
      }
    }

  }

  /**
   * Gets the first node by XPath.
   *
   * @param $dom
   * @param $xpath
   * @param null $parent
   * @return null
   */
  private function getNode($dom, $xpath, $parent = null)
  {
    $nodes = $this->getNodes($dom, $xpath, $parent);
    return $nodes->length === 0 ? null : $nodes[0];
  }

  /**
   * Gets all nodes by XPath.
   *
   * @param $dom
   * @param $xpath
   * @param null $parent
   * @return \DOMNodeList
   */
  private function getNodes($dom, $xpath, $parent = null)
  {
    $DomXpath = new DOMXPath($dom);
    $nodes = $DomXpath->query($xpath, $parent);
    return $nodes;
  }

  /**
   * Makes absolute URL by url part and base url.
   *
   * @param $url
   * @param $base
   * @return string
   */
  private function makeAbsoluteUrl($url, $base)
  {
    // Return base if no url
    if (!$url) {
      return $base;
    }

    // Return if already absolute URL
    if (parse_url($url, PHP_URL_SCHEME) != '') {
      return $url;
    }

    // Urls only containing query or anchor
    if ($url[0] == '#' || $url[0] == '?') {
      return $base . $url;
    }

    // Parse base URL and convert to local variables: $scheme, $host, $path
    $urlParts = parse_url($base);

    // If no path, use /
    if (!isset($urlParts['path'])) {
      $urlParts['path'] = '/';
    }

    // Remove non-directory element from path
    $urlParts['path'] = preg_replace('#/[^/]*$#', '', $urlParts['path']);

    // Destroy path if relative url points to root
    if ($url[0] == '/') {
      $urlParts['path'] = '';
    }

    // Dirty absolute URL
    $abs = $urlParts['host'] . $urlParts['path'] . '/' . $url;

    // Replace '//' or '/./' or '/foo/../' with '/'
    $re = array('#(/\.?/)#', '#/(?!\.\.)[^/]+/\.\./#');
    for ($n = 1; $n > 0; $abs = preg_replace($re, '/', $abs, -1, $n)) {
    }

    return $urlParts['scheme'] . '://' . $abs;
  }

  /**
   * Sets $this->concurrency value.
   *
   * @param int $concurrency
   */
  public function setConcurrency($concurrency) {
    $options = [
        'options' => [
            'min_range' => 1
        ]
    ];

    if (filter_var($concurrency, FILTER_VALIDATE_INT, $options) === false)
      throw new \InvalidArgumentException('Concurrency value should be integer and >= 1. Input was: ' . $concurrency);
    $this->concurrency = $concurrency;
  }

  /**
   * Sets $this->maxResultsPerAuthor value.
   *
   * @param int $maxResultsPerAuthor
   */
  public function setMaxResultsPerAuthor($maxResultsPerAuthor) {
    $options = [
        'options' => [
            'min_range' => 0
        ]
    ];

    if (filter_var($maxResultsPerAuthor, FILTER_VALIDATE_INT, $options) === false)
      throw new \InvalidArgumentException('Max Results Per Author value should be positive integer. Input was: ' . $maxResultsPerAuthor);
    $this->maxResultsPerAuthor = $maxResultsPerAuthor;
  }

  /**
   * Sets $this->wait value.
   *
   * @param int $wait
   */
  public function setWait($wait) {
    $options = [
        'options' => [
            'min_range' => 0
        ]
    ];

    if (filter_var($wait, FILTER_VALIDATE_INT, $options) === false)
      throw new \InvalidArgumentException('Wait value should be positive integer. Input was: ' . $wait);
    $this->wait = $wait;
  }

  /**
   * Sets $this->startDate value.
   *
   * @param int $startDate
   */
  public function setStartDate($startDate) {
    $this->startDate = DateTime::createFromFormat('Y-m-d', $startDate);
    if($this->startDate === false)
      throw new \InvalidArgumentException('Start Date format should be Y-m-d. Input was: ' . $startDate);
  }

  /**
   * Sets $this->endDate value.
   *
   * @param int $endDate
   */
  public function setEndDate($endDate) {
    $this->endDate = DateTime::createFromFormat('Y-m-d', $endDate);
    if($this->endDate === false)
      throw new \InvalidArgumentException('End Date format should be Y-m-d. Input was: ' . $endDate);
  }

  /**
   * Sets $this->baseUri value.
   *
   * @param string $baseUri
   */
  public function setBaseUri($baseUri) {
    if (filter_var($baseUri, FILTER_VALIDATE_URL) === false)
      throw new \InvalidArgumentException('Invalid Base Uri. Input was: ' . $baseUri);
    $this->baseUri = $baseUri;
  }
}