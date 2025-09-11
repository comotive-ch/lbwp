<?php

namespace LBWP\Theme\Component\Selection\Import;

use LBWP\Util\ArrayManipulation;
use LBWP\Util\Strings;
use LBWP\Util\Date;

/**
 * Implementation for the scope importer
 * @package LBWP\Theme\Component\Selection\Import
 * @author Michael Sebel <michael@comotive.ch>
 */
class Scope extends Base
{
  /**
   * @var string the REST api to get selection information
   */
  const SELECTION_API = 'https://scope-lb.api.thescope.com/public/api/v1/box/{boxId}/publication?maxNumberOfSelections={selectionLimit}';
  /**
   * @var string the image proxy to import files
   */
  const IMAGE_PROXY = 'https://cy1er32c.cloudimg.io/crop/300x200/tjpg.q90/';
  /**
   * @var string scopes internal name of article lists
   */
  const ARTICLE_LIST = 'ARTICLELIST';

  /**
   * @return array a key value list of scope selection ids and their name
   */
  protected function getSelectionList()
  {
    $url = str_replace(
      array('{boxId}', '{selectionLimit}'),
      array($this->config['boxId'], $this->config['selectionLimit']),
      self::SELECTION_API
    );

    // Get the raw results from API
    $raw = json_decode(Strings::genericRequest($url, array(), 'GET'), true);
    $selections = array();

    if (is_array($raw) && isset($raw[0]['body'])) {
      foreach ($raw as $selection) {
        $name = Strings::chopToWords($selection['body']['intro']['textContent'], 10, true);
        $name.= ', (' . count($this->getArticles($selection['body']['mainSection'])) . ' Artikel)';
        $selections[$selection['id']] = $name;
      }
    }

    return $selections;
  }

  /**
   * @param array $selection
   * @return array a list of articles within the selection
   */
  protected function getArticles($selection)
  {
    $articles = array();
    foreach ($selection as $part) {
      if ($part['type'] == self::ARTICLE_LIST || isset($part['articlesContent'])) {
        foreach ($part['articlesContent'] as $article) {
          $articles[] = $article;
        }
      }
    }

    return $articles;
  }

  /**
   * @param int $id the scope selection id
   * @return array the abstracted selection item
   */
  protected function getSelectionData($id)
  {
    // Prepare the abstract result array
    $result = array(
      'selection' => array(
        'id' => $id,
        'timestamp' => 0,
        'comment' => ''
      ),
      'articles' => array()
    );

    $url = str_replace(
      array('{boxId}', '{selectionLimit}'),
      array($this->config['boxId'], $this->config['selectionLimit']),
      self::SELECTION_API
    );

    $selection = array();
    $raw = json_decode(Strings::genericRequest($url, array(), 'GET'), true);
    if (is_array($raw) && isset($raw[0]['body'])) {
      foreach ($raw as $candidate) {
        if ($candidate['id'] == $id) {
          $selection = $candidate;
          break;
        }
      }
    }

    // Set the main data
    $result['selection']['comment'] = $selection['body']['intro']['textContent'];

    // Add the abstracted articles
    foreach ($this->getArticles($selection['body']['mainSection']) as $article) {
      $result['articles'][] = $this->normalizeArticle($article);
    }

    // Order by date if needed
    if ($this->config['orderByDate']) {
      usort($result['articles'], function($a, $b) {
        if ($a['timestamp'] > $b['timestamp']) {
          return -1;
        } else if ($a['timestamp'] < $b['timestamp']) {
          return 1;
        }
        return 0;
      });
    }

    return $result;
  }

  /**
   * @param array $article
   * @return array
   */
  public function normalizeArticle($article)
  {
    // Set the tags and categories if given
    $terms = array();
    foreach ($this->config['taxonomyMap'] as $remote => $local) {
      if (isset($article[$remote]) && count($article[$remote]) > 0) {
        foreach ($article[$remote] as $term) {
          $terms[$local][$term['id']] = $term['title'];
        }
      }
    }
    $item = array(
      'title' => $article['articleTitle'],
      'content' => $article['articleComment'],
      'url' => $article['articleUrl'],
      'source' => $article['articleSource'],
      'terms' => $terms,
      'image' => ''
    );
    // Set image if given
    if (strlen($article['articleImageUrl']) > 0) {
      $item['image'] = self::IMAGE_PROXY . $article['articleImageUrl'];
    }
    // Save timestamp on article if ordering is active
    if ($this->config['orderByDate']) {
      $item['timestamp'] = strtotime($article['articlePublicationDate']);
      $item['date'] = Date::getTime(Date::SQL_DATETIME, $item['timestamp']);
    }

    return $item;
  }
}