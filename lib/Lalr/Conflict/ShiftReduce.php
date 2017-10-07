<?php
declare(strict_types=1);

namespace PhpYacc\Lalr\Conflict;

use PhpYacc\Grammar\Symbol;
use PhpYacc\Lalr\{
    Conflict,
    State
};

class ShiftReduce extends Conflict {

    protected $state;
    protected $reduce;

    public function __construct(State $state, int $reduce, Symbol $symbol, Conflict $next = null)
    {
        $this->state = $state;
        $this->reduce = $reduce;
        parent::__construct($symbol, $next);
    }

    public function isShiftReduce(): bool
    {
        return true;
    }

    public function state(): State
    {
        return $this->state;
    }

    public function reduce(): int
    {
        return $this->reduce;
    }

}