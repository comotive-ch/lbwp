<?php

namespace LBWP\Helper;

/**
 * Helper class for DNS queries
 * @author Michael Sebel <michael@comotive.ch>
 */
class DnsQuery
{
  /**
   * Check if domain exists and has at least one MX record
   */
  public static function domainHasMxRecord(string $domain): bool
  {
    $mxRecords = self::getMxRecords($domain);
    return !empty($mxRecords);
  }

  /**
   * Get all MX records for a domain
   */
  public static function getMxRecords(string $domain): array
  {
    $command = sprintf('dig +short MX %s', escapeshellarg($domain));
    $output = shell_exec($command);

    if (empty($output)) {
      return [];
    }

    $lines = array_filter(array_map('trim', explode("\n", $output)));
    $mxRecords = [];

    foreach ($lines as $line) {
      if (preg_match('/^(\d+)\s+(.+)$/', $line, $matches)) {
        $mxRecords[] = [
          'priority' => (int)$matches[1],
          'hostname' => rtrim($matches[2], '.')
        ];
      }
    }

    // Sort by priority (lower number = higher priority)
    usort($mxRecords, fn($a, $b) => $a['priority'] <=> $b['priority']);

    return $mxRecords;
  }

  /**
   * Get A records for a domain
   */
  public static function getARecords(string $domain): array
  {
    $command = sprintf('dig +short A %s', escapeshellarg($domain));
    $output = shell_exec($command);

    if (empty($output)) {
      return [];
    }

    return array_filter(array_map('trim', explode("\n", $output)));
  }

  /**
   * Check if domain exists (has any DNS records)
   */
  public static function domainExists(string $domain): bool
  {
    $command = sprintf('dig +short ANY %s', escapeshellarg($domain));
    $output = shell_exec($command);

    return !empty(trim($output));
  }

  /**
   * Get specific DNS record type
   */
  public static function getRecords(string $domain, string $type = 'A'): array
  {
    $allowedTypes = ['A', 'AAAA', 'MX', 'TXT', 'NS', 'CNAME', 'SOA'];

    if (!in_array(strtoupper($type), $allowedTypes)) {
      throw new \InvalidArgumentException("Unsupported DNS record type: $type");
    }

    $command = sprintf('dig +short %s %s', escapeshellarg($type), escapeshellarg($domain));
    $output = shell_exec($command);

    if (empty($output)) {
      return [];
    }

    return array_filter(array_map('trim', explode("\n", $output)));
  }
}