<?php
require_once '../../../../../wp-load.php';

use LBWP\Helper\DnsQuery;

// Example domains to test
$domains = ['www.comotive.ch', 'comotive.ch', 'nonexistentdomain12345.com'];

foreach ($domains as $domain) {
  echo "Testing domain: $domain\n";
  echo str_repeat('-', 40) . "\n";

  // Check if domain has MX records
  if (DnsQuery::domainHasMxRecord($domain)) {
    echo "✓ Domain has MX records\n";

    // Get all MX records
    $mxRecords = DnsQuery::getMxRecords($domain);
    foreach ($mxRecords as $mx) {
      echo "  MX: {$mx['priority']} {$mx['hostname']}\n";
    }
  } else {
    echo "✗ No MX records found\n";
  }

  // Check if domain exists at all
  if (DnsQuery::domainExists($domain)) {
    echo "✓ Domain exists\n";

    // Get A records
    $aRecords = DnsQuery::getARecords($domain);
    if (!empty($aRecords)) {
      echo "  A records: " . implode(', ', $aRecords) . "\n";
    }
  } else {
    echo "✗ Domain does not exist\n";
  }

  echo "\n";
}

// Quick check for specific domain
$testDomain = 'example.com';
echo DnsQuery::domainHasMxRecord($testDomain)
  ? "$testDomain has mail servers"
  : "$testDomain has no mail servers";
echo "\n";