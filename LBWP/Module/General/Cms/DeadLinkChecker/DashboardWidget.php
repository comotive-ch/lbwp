<?php

namespace LBWP\Module\General\Cms\DeadLinkChecker;

use LBWP\Util\Strings;

/**
 * Dashboard widget showing a summary of the dead link checker results
 * @package LBWP\Module\General\Cms\DeadLinkChecker
 * @author Mirko Baffa <mirko@comotive.ch>
 */
class DashboardWidget
{
  /**
   * @var string id of the dashboard widget
   */
  const WIDGET_ID = 'lbwp-dead-link-checker';
  /**
   * @var int max number of failed links listed in the widget
   */
  const MAX_LISTED_LINKS = 5;

  /**
   * @var LinkTable
   */
  protected $table;

  /**
   * @param LinkTable $table the link table
   */
  public function __construct(LinkTable $table)
  {
    $this->table = $table;
  }

  /**
   * Register the hook to add the widget to the dashboard
   *
   * @return void
   */
  public function setup()
  {
    add_action('wp_dashboard_setup', [$this, 'addWidget']);
  }

  /**
   * Add the dashboard widget for administrators
   *
   * @return void
   */
  public function addWidget()
  {
    if (!current_user_can('administrator')) {
      return;
    }

    wp_add_dashboard_widget(self::WIDGET_ID, 'Link Checker', [$this, 'render']);
  }

  /**
   * Print the widget html
   *
   * @return void
   */
  public function render()
  {
    $summary = $this->table->getSummary();

    if ($summary['checked'] === 0) {
      echo '<p>Es wurden noch keine Links überprüft. Die Prüfung läuft täglich automatisch.</p>';
      return;
    }

    echo $this->getSummaryHtml($summary);
    echo $this->getFailedLinksHtml($this->table->getByStatus([LinkTable::STATUS_FAILED], self::MAX_LISTED_LINKS));
    echo '<p><a href="' . esc_url(admin_url('tools.php?page=lbwp-dlc')) . '" class="button">Alle Ergebnisse anzeigen</a></p>';
  }

  /**
   * Build the summary line with the number of links per status
   *
   * @param array $summary result of LinkTable::getSummary()
   * @return string html of the summary
   */
  protected function getSummaryHtml($summary)
  {
    $failed = $summary[LinkTable::STATUS_FAILED];
    $review = $summary[LinkTable::STATUS_BLOCKED] + $summary[LinkTable::STATUS_UNREACHABLE];

    return '
      <p>
        <strong>' . $summary['checked'] . '</strong> von ' . $summary['total'] . ' Links überprüft:
        ' . $summary[LinkTable::STATUS_OK] . ' korrekt,
        ' . $summary[LinkTable::STATUS_REDIRECT] . ' Weiterleitungen,
        ' . $review . ' zu prüfen,
        <strong' . ($failed > 0 ? ' style="color:#d63638"' : '') . '>' . $failed . ' fehlerhaft</strong>
      </p>
    ';
  }

  /**
   * Build the list of the most recently checked failed links
   *
   * @param array $rows failed link rows
   * @return string html list, or empty string if there are no failed links
   */
  protected function getFailedLinksHtml(array $rows)
  {
    if (count($rows) === 0) {
      return '';
    }

    $html = '<ul>';
    foreach ($rows as $row) {
      $html .= $this->getFailedLinkItemHtml($row);
    }

    return $html . '</ul>';
  }

  /**
   * Build a single list item for a failed link
   *
   * @param array $row link row
   * @return string html list item
   */
  protected function getFailedLinkItemHtml($row)
  {
    return '
      <li>
        <a href="' . esc_url($row['url']) . '" target="_blank">' . esc_html(Strings::chopStringCenter($row['url'], 40, 10)) . '</a><br>
        <span class="description">
          ' . esc_html(StatusLabel::get($row)) . ' · in
          <a href="' . esc_url(get_edit_post_link($row['source_id'])) . '">' . esc_html(get_the_title($row['source_id'])) . '</a>
        </span>
      </li>
    ';
  }
}
