<?php

namespace LBWP\Module\General\Cms\DeadLinkChecker;

use LBWP\Util\Strings;

/**
 * Tools page listing failed links and links that need a manual review
 * @package LBWP\Module\General\Cms\DeadLinkChecker
 * @author Mirko Baffa <mirko@comotive.ch>
 */
class AdminPage
{
  /**
   * @var string menu slug of the page
   */
  const SLUG = 'lbwp-dlc';
  /**
   * @var string nonce action for all row and page actions
   */
  const NONCE_ACTION = 'lbwp-dlc-action';
  /**
   * @var int rows per page in the failed links table
   */
  const PAGE_SIZE = 100;
  /**
   * @var string[] actions that can be triggered from the page
   */
  const ACTIONS = ['ignore', 'recheck', 'rebuild'];
  /**
   * @var array notices shown after an action
   */
  const NOTICES = [
    'ignore' => 'Der Link wird nicht mehr geprüft.',
    'recheck' => 'Der Link wurde erneut geprüft.',
    'rebuild' => 'Alle Inhalte wurden neu indexiert. Neue Links werden in den nächsten Tagen geprüft.',
  ];

  /**
   * @var LinkTable
   */
  protected $table;
  /**
   * @var LinkIndexer
   */
  protected $indexer;
  /**
   * @var CheckRunner
   */
  protected $runner;

  /**
   * @param LinkTable $table the link table
   * @param LinkIndexer $indexer the indexer
   * @param CheckRunner $runner the check runner
   */
  public function __construct(LinkTable $table, LinkIndexer $indexer, CheckRunner $runner)
  {
    $this->table = $table;
    $this->indexer = $indexer;
    $this->runner = $runner;
  }

  /**
   * Register the page and its action handler
   *
   * @return void
   */
  public function setup()
  {
    add_action('admin_menu', [$this, 'registerPage']);
    add_action('load-tools_page_' . self::SLUG, [$this, 'handleAction']);
  }

  /**
   * Add the page to the tools menu
   *
   * @return void
   */
  public function registerPage()
  {
    add_management_page('Link Checker', 'Link Checker', 'administrator', self::SLUG, [$this, 'render']);
  }

  /**
   * Run a requested action before output starts, then redirect back to the page
   *
   * @return void
   */
  public function handleAction()
  {
    $action = sanitize_key($_GET['dlc_action'] ?? '');
    if (!in_array($action, self::ACTIONS) || !current_user_can('administrator')) {
      return;
    }

    check_admin_referer(self::NONCE_ACTION);
    $hash = preg_replace('/[^a-f0-9]/', '', $_GET['hash'] ?? '');

    if ($action === 'ignore') {
      $this->table->ignore($hash);
    } else if ($action === 'recheck') {
      $this->runner->checkNow($hash);
    } else if ($action === 'rebuild') {
      set_time_limit(600);
      $this->indexer->rebuild();
    }

    wp_safe_redirect(add_query_arg('dlc_done', $action, $this->getPageUrl()));
    exit;
  }

  /**
   * Print the page
   *
   * @return void
   */
  public function render()
  {
    $summary = $this->table->getSummary();
    $review = [LinkTable::STATUS_BLOCKED, LinkTable::STATUS_UNREACHABLE];

    echo '<div class="wrap"><h1>Überprüfen von Links</h1>';
    echo $this->getNoticeHtml();
    echo '<p>Alle Links in veröffentlichten Inhalten werden regelmässig überprüft. Fehlerhafte Links werden unten aufgelistet und sollten bereinigt werden.</p>';
    echo $this->getStatsHtml($summary);
    echo $this->getFailedSectionHtml();
    echo $this->getReviewSectionHtml($review, $summary);
    echo $this->getFooterHtml($summary);
    echo '</div>';
  }

  /**
   * Build the notice after an action was done
   *
   * @return string notice html or empty string
   */
  protected function getNoticeHtml()
  {
    $done = sanitize_key($_GET['dlc_done'] ?? '');
    if (!isset(self::NOTICES[$done])) {
      return '';
    }

    return '<div class="notice notice-success is-dismissible"><p>' . esc_html(self::NOTICES[$done]) . '</p></div>';
  }

  /**
   * Build the statistics box
   *
   * @param array $summary result of LinkTable::getSummary()
   * @return string html
   */
  protected function getStatsHtml(array $summary)
  {
    $review = $summary[LinkTable::STATUS_BLOCKED] + $summary[LinkTable::STATUS_UNREACHABLE];

    return '
      <div class="links-stats">
        <div class="links-num">
          <p><span>Überprüfte Links</span>' . $summary['checked'] . '/' . $summary['total'] . '</p>
        </div>
        <div class="links-results">
          <p><span>Korrekte Links</span>' . $summary[LinkTable::STATUS_OK] . '</p>
          <p><span>Weiterleitungen</span>' . $summary[LinkTable::STATUS_REDIRECT] . '</p>
          <p><span>Zu prüfen</span>' . $review . '</p>
          <p><span>Fehlerhafte Links</span>' . $summary[LinkTable::STATUS_FAILED] . '</p>
        </div>
      </div>
    ';
  }

  /**
   * Build the paginated table of failed links
   *
   * @return string html
   */
  protected function getFailedSectionHtml()
  {
    $statuses = [LinkTable::STATUS_FAILED];
    $total = $this->table->countByStatus($statuses);
    if ($total === 0) {
      return '<h2>Fehlerhafte Links</h2><p>Es wurden keine fehlerhaften Links gefunden.</p>';
    }

    $page = max(1, intval($_GET['paged'] ?? 1));
    $rows = $this->table->getByStatus($statuses, self::PAGE_SIZE, ($page - 1) * self::PAGE_SIZE);
    $pagination = paginate_links([
      'base' => add_query_arg('paged', '%#%', $this->getPageUrl()),
      'format' => '',
      'current' => $page,
      'total' => intval(ceil($total / self::PAGE_SIZE)),
    ]);

    return '<h2>Fehlerhafte Links (' . $total . ')</h2>' . $this->getTableHtml($rows) .
      ($pagination ? '<div class="tablenav"><div class="tablenav-pages">' . $pagination . '</div></div>' : '');
  }

  /**
   * Build the collapsible table of links that are blocked or unreachable
   *
   * @param string[] $statuses statuses to list
   * @param array $summary result of LinkTable::getSummary()
   * @return string html or empty string if there are none
   */
  protected function getReviewSectionHtml(array $statuses, array $summary)
  {
    $rows = $this->table->getByStatus($statuses, self::PAGE_SIZE);
    if (count($rows) === 0) {
      return '';
    }

    return '
      <details style="clear:both;padding-top:20px">
        <summary><strong>Zu prüfen (' . ($summary[LinkTable::STATUS_BLOCKED] + $summary[LinkTable::STATUS_UNREACHABLE]) . ')</strong></summary>
        <p>Diese Links blockieren automatische Prüfungen oder waren wiederholt nicht erreichbar. Sie funktionieren oft im Browser, bitte von Hand prüfen.</p>
        ' . $this->getTableHtml($rows) . '
      </details>
    ';
  }

  /**
   * Build a links table
   *
   * @param array $rows link rows
   * @return string html
   */
  protected function getTableHtml(array $rows)
  {
    $html = '
      <table class="wp-list-table widefat striped posts float-left-top-margin dlc-table">
        <thead><tr><th>Link</th><th>Quelle</th><th>Geprüft</th><th>Status</th><th>Aktionen</th></tr></thead>
        <tbody>
    ';

    foreach ($rows as $row) {
      $html .= $this->getRowHtml($row);
    }

    return $html . '</tbody></table>';
  }

  /**
   * Build a single table row
   *
   * @param array $row link row
   * @return string html
   */
  protected function getRowHtml(array $row)
  {
    return '
      <tr>
        <td><a href="' . esc_url($row['url']) . '" target="_blank">' . esc_html(Strings::chopStringCenter($row['url'], 50, 10)) . '</a></td>
        <td><a href="' . esc_url(get_edit_post_link($row['source_id'])) . '" target="_blank">' . esc_html(get_the_title($row['source_id'])) . '</a></td>
        <td>' . ($row['checked_at'] > 0 ? esc_html(wp_date('d.m.Y, H:i', $row['checked_at'])) : 'Nein') . '</td>
        <td>' . esc_html(StatusLabel::get($row)) . '</td>
        <td>
          <a href="' . esc_url($this->getActionUrl('recheck', $row['url_hash'])) . '">Prüfen</a> |
          <a href="' . esc_url($this->getActionUrl('ignore', $row['url_hash'])) . '">Ignorieren</a>
        </td>
      </tr>
    ';
  }

  /**
   * Build the footer with index info and rebuild button
   *
   * @param array $summary result of LinkTable::getSummary()
   * @return string html
   */
  protected function getFooterHtml(array $summary)
  {
    $lastRebuild = $this->indexer->getLastRebuild();

    return '
      <p style="clear:both;padding-top:20px">
        ' . $summary[LinkTable::STATUS_NEW] . ' Links warten auf die erste Prüfung.
        Letzte vollständige Indexierung: ' . ($lastRebuild > 0 ? esc_html(wp_date('d.m.Y, H:i', $lastRebuild)) : 'noch nie') . '.
      </p>
      <p><a href="' . esc_url($this->getActionUrl('rebuild')) . '" class="button">Neu indexieren</a></p>
    ';
  }

  /**
   * Get the url of the page
   *
   * @return string url
   */
  protected function getPageUrl()
  {
    return admin_url('tools.php?page=' . self::SLUG);
  }

  /**
   * Get a nonced url triggering an action
   *
   * @param string $action one of self::ACTIONS
   * @param string $hash url hash the action applies to
   * @return string url
   */
  protected function getActionUrl($action, $hash = '')
  {
    $args = ['dlc_action' => $action];
    if (strlen($hash) > 0) {
      $args['hash'] = $hash;
    }

    return wp_nonce_url(add_query_arg($args, $this->getPageUrl()), self::NONCE_ACTION);
  }
}
