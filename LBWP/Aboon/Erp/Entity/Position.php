<?php

namespace LBWP\Aboon\Erp\Entity;

/**
 * Very simple access class for type security
 * @package LBWP\Aboon\Erp\Entity
 * @author Michael Sebel <michael@comotive.ch>
 */
class Position
{
  /**
   * Type secured public vars
   */
  public string $sku = '';
  public string $line = '';
  public int $qty = 0;
  public float $tax = 0.0;
  public float $total = 0.0;
  public bool $taxIncluded = false;

  /**
   * @param string $sku
   * @param string $line
   * @param int $qty
   * @param float $tax
   * @param float $total
   * @param bool $taxIncl
   */
  public function __construct(string $sku, string $line, int $qty, float $tax, float $total, bool $taxIncl)
  {
    $this->sku = $sku;
    $this->line = $line;
    $this->qty = $qty;
    $this->tax = $tax;
    $this->total = $total;
    $this->taxIncl = $taxIncl;
  }
}