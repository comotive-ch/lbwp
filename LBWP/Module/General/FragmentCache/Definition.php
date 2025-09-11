<?php

namespace LBWP\Module\General\FragmentCache;

/**
 * Definition for a fragment
 * @author Michael Sebel <michael@comotive.ch>
 */
interface Definition
{
  /**
   * This is called in the backend to register invalidations to the fragment
   */
  public function registerInvalidation();

  /**
   * This is called in the frontend to register hooks to provide a cached version of a fragment
   */
  public function registerFrontend();
}