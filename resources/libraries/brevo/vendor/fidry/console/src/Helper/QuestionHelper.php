<?php

/*
 * This file is part of the Fidry\Console package.
 *
 * (c) Théo FIDRY <theo.fidry@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
declare (strict_types=1);
namespace BrevoScoped\Fidry\Console\Helper;

use BrevoScoped\Fidry\Console\IO;
use BrevoScoped\Symfony\Component\Console\Exception\RuntimeException;
use BrevoScoped\Symfony\Component\Console\Helper\QuestionHelper as SymfonyQuestionHelper;
use BrevoScoped\Symfony\Component\Console\Question\Question;
final class QuestionHelper
{
    private SymfonyQuestionHelper $helper;
    public function __construct()
    {
        $this->helper = new SymfonyQuestionHelper();
    }
    /**
     * Asks a question to the user.
     *
     * @throws RuntimeException If there is no data to read in the input stream
     *
     * @return mixed The user answer
     */
    public function ask(IO $io, Question $question): mixed
    {
        return $this->helper->ask($io->getInput(), $io->getOutput(), $question);
    }
}
