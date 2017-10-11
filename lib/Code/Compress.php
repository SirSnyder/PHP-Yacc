<?php
declare(strict_types=1);

namespace PhpYacc\Code;

use PhpYacc\Grammar\Context;

require_once __DIR__ . "/functions.php";

class Compress
{
    protected $result;
    /**
     * @var Context $context
     */
    protected $context;

    public function compress(Context $context)
    {
        $this->result = new CompressResult($context->nstates, $context->nterminals);
        $this->context = $context;

        $this->makeup_table2();
        return $this->result;
    }

    protected function compute_preimages()
    {
        $primv = [];

        for ($i = 0; $i < $this->context->nstates; $i++) {
            $primv[$i] = new Preimage($i);
        }

        for ($i = 0; $i < count($this->result->class2nd); $i++) {
            for ($j = 0; $j < $this->context->nterminals; $j++) {
                $s = $this->result->class_action[$i][$j];
                if ($s > 0) {
                    $primv[$s]->classes[] = $i;
                }
            }
        }

        usort($primv, Preimage::class . "::compare");

        $nprims = 0;
        for ($i = 0; $i < $this->context->nstates; $i++) {
            $p = $primv[$i];
            $this->result->prims[$nprims] = $p;
            for (; $i < $this->context->nstates && Preimage::compare($p, $primv[$i]) === 0; $i++) {
                $this->result->primof[$primv[$i]->index] = $p;
            }
            $p->index = $nprims++;
        }
    }

    protected function encode_shift_reduce(array $t, int $count = -1): array
    {
        for ($i = 0; $i < $this->context->nnonleafstates; $i++) {
            if (!isset($t[$i])) {
                break;
            }
            if ($t[$i] >= $this->context->nnonleafstates) {
                $t[$i] = $this->context->nnonleafstates + $this->result->default_act[$t[$i]];
            }
        }
        if ($count === -1) {
            return $t;
        }
        return array_slice($t, 0, $count);
    }

    protected function makeup_table2()
    {
        $this->result->term_action = array_fill(0, $this->context->nnonleafstates, 0);
        $this->result->class_action = array_fill(0, $this->context->nnonleafstates * 2, 0);
        $this->result->nonterm_goto = array_fill(0, $this->context->nnonleafstates, 0);
        $this->result->default_act = array_fill(0, $this->context->nstates, 0);
        $this->result->default_goto = array_fill(0, $this->context->nnonterminals, 0);

        $this->result->resetFrequency();
        $this->result->state_imagesorted = array_fill(0, $this->context->nnonleafstates, 0);
        $this->result->class_of = array_fill(0, $this->context->nstates, 0);

        for ($i = 0; $i < $this->context->nnonleafstates; $i++) {
            $this->result->term_action[$i] = array_fill(0, $this->context->nterminals, CompressResult::VACANT);
            $this->result->nonterm_goto[$i] = array_fill(0, $this->context->nnonterminals, CompressResult::VACANT);

            foreach ($this->context->states[$i]->shifts as $shift) {
                if ($shift->through->isTerminal()) {
                    $this->result->term_action[$i][$shift->through->code] = $shift->number;
                } else {
                    $this->result->nonterm_goto[$i][$shift->through->nb] = $shift->number;
                }
            }
            foreach ($this->context->states[$i]->reduce as $reduce) {
                if ($reduce->symbol->isNilSymbol()) {
                    break;
                }
                $this->result->term_action[$i][$reduce->symbol->code] = -$this->result->encode_rederr($reduce->number);
            }
            $this->result->state_imagesorted[$i] = $i;
        }

        foreach ($this->context->states as $key => $state) {
            foreach ($state->reduce as $r) {
                if ($r->symbol->isNilSymbol()) {
                    break;
                }
            }
            $this->result->default_act[$key] = $this->result->encode_rederr($r->number);
        }

        for ($j = 0; $j < $this->context->nnonterminals; $j++) {
            $max = 0;
            $maxst = CompressResult::VACANT;
            $this->result->resetFrequency();

            for ($i = 0; $i < $this->context->nnonleafstates; $i++) {
                $st = $this->result->nonterm_goto[$i][$j];
                if ($st > 0) {
                    $this->result->frequency[$st]++;
                    if ($this->result->frequency[$st] > $max) {
                        $max = $this->result->frequency[$st];
                        $maxst = $st;
                    }
                }
            }
            $this->result->default_goto[$j] = $maxst;
        }
        # 847

        usort($this->result->state_imagesorted, [$this->result, 'cmp_states']);

        $j = 0;

        for ($i = 0; $i < $this->context->nnonleafstates;) {
            $k = $this->result->state_imagesorted[$i];
            $this->result->class_action[$j] = $this->result->term_action[$k];
            for (; $i < $this->context->nnonleafstates && $this->result->cmp_states($this->result->state_imagesorted[$i], $k) === 0; $i++) {
                $this->result->class_of[$this->result->state_imagesorted[$i]] = $j;
            }
            $j++;
        }
        $this->result->nclasses = $j;

        if (DEBUG) {
            $this->context->debug("State=>class:\n");
            for ($i = 0; $i < $this->context->nnonleafstates; $i++) {
                if ($i % 10 === 0) {
                    $this->context->debug("\n");
                }
                $this->context->debug(sprintf("%3d=>%-3d ", $i, $this->result->class_of[$i]));
            }
            $this->context->debug("\n");
        }

        $this->compute_preimages();

        if (DEBUG) {
            $this->print_table();
        }

        $this->extract_common();

        $this->authodox_table();
    }

    protected function print_table()
    {
        $this->context->debug("\nTerminal action:\n");
        $this->context->debug(sprintf("%8.8s", "T\\S"));
        for ($i = 0; $i < $this->result->nclasses; $i++) {
            $this->context->debug(sprintf("%4d", $i));
        }
        $this->context->debug("\n");
        for ($j = 0; $j < $this->result->nterms; $j++) {
            for ($i = 0; $i < $this->context->nnonleafstates; $i++) {
                if (!is_vacant($this->result->term_action[$i][$j])) {
                    break;
                }
            }
            if ($i < $this->context->nnonleafstates) {
                $this->context->debug(sprintf("%8.8s", $this->context->symbol($j)->name));
                for ($i = 0; $i < $this->result->nclasses; $i++) {
                    $this->context->debug(printact($this->result->class_action[$i][$j]));
                }
                $this->context->debug("\n");
            }
        }

        $this->context->debug("\nNonterminal GOTO table:\n");
        $this->context->debug(sprintf("%8.8s", "T\\S"));
        for ($i = 0; $i < $this->context->nnonleafstates; $i++) {
            $this->context->debug(sprintf("%4d", $i));
        }
        $this->context->debug("\n");
        foreach ($this->context->nonterminals as $symbol) {
            for ($i = 0; $i < $this->context->nnonleafstates; $i++) {
                if ($this->result->nonterm_goto[$i][$symbol->nb] > 0) {
                    break;
                }
            }
            if ($i < $this->context->nnonleafstates) {
                $this->context->debug(sprintf("%8.8s", $symbol->name));
                for ($i = 0; $i < $this->context->nnonleafstates; $i++) {
                    $this->context->debug(printact($this->result->nonterm_goto[$i][$symbol->nb]));
                }
                $this->context->debug("\n");
            }
        }

        $this->context->debug("\nNonterminal GOTO table:\n");
        $this->context->debug(sprintf("%8.8s default", "T\\S"));
        for ($i = 0; $i < $this->context->nnonleafstates; $i++) {
            $this->context->debug(sprintf("%4d", $i));
        }
        $this->context->debug("\n");
        foreach ($this->context->nonterminals as $symbol) {
            for ($i = 0; $i < $this->context->nnonleafstates; $i++) {
                if ($this->result->nonterm_goto[$i][$symbol->nb] > 0) {
                    break;
                }
            }
            if ($i < $this->context->nnonleafstates) {
                $this->context->debug(sprintf("%8.8s", $symbol->name));
                $this->context->debug(sprintf("%8d", $this->result->default_goto[$symbol->nb]));
                for ($i = 0; $i < $this->context->nnonleafstates; $i++) {
                    if ($this->result->nonterm_goto[$i][$symbol->nb] === $this->result->default_goto[$symbol->nb]) {
                        $this->context->debug("  = ");
                    } else {
                        $this->context->debug(printact($this->result->nonterm_goto[$i][$symbol->nb]));
                    }
                }
                $this->context->debug("\n");
            }
        }
    }

    protected function extract_common()
    {
        $this->result->class2nd = array_fill(0, $this->result->nclasses, -1);

        $alist = null;
        $n = 0;

        foreach ($this->result->prims as $prim) {
            if (count($prim->classes) < 2) {
                continue;
            }
            $p = new Auxiliary;
            $this->best_covering($p, $prim);
            if ($p->gain < 1) {
                continue;
            }
            $p->preimage = $prim;
            $p->next = $alist;
            $alist = $p;
            $n++;
        }

        if (DEBUG) {
            $this->context->debug("\nCandidates of aux table:\n");
            for ($p = $alist; $p !== null; $p = $p->next) {
                $this->context->debug(sprintf("Aux = (%d) ", $p->gain));
                $f = 0;
                for ($j = 0; $j < $this->result->nterms; $j++) {
                    if (!is_vacant($p->table[$j])) {
                        $this->context->debug(sprintf($f++ ? ",%d" : "%d", $p->table[$j]));
                    }
                }
                $this->context->debug(" * ");
                for ($j = 0; $j < count($p->preimage->classes); $j++) {
                    $this->context->debug(sprintf($j ? ",%d" : "%d", $p->preimage->classes[$j]));
                }
                $this->context->debug("\n");
            }
            $this->context->debug("Used aux table:\n");
        }
        $this->result->naux = $this->result->nclasses;
        for (;;) {
            $maxgain = 0;
            $maxaux = null;
            $pre = null;
            $maxpre = null;
            for ($p = $alist; $p != null; $p = $p->next) {
                if ($p->gain > $maxgain) {
                    $maxgain = $p->gain;
                    $maxaux = $p;
                    $maxpre = $pre;
                }
                $pre = $p;
            }

            if ($maxaux === null) {
                break;
            }

            if ($maxpre) {
                $maxpre->next = $maxaux->next;
            } else {
                $alist = $maxaux->next;
            }

            $maxaux->index = $this->result->naux;

            for ($j = 0; $j < count($maxaux->preimage->classes); $j++) {
                $cl = $maxaux->preimage->classes[$j];
                if (eq_row($this->result->class_action[$cl], $maxaux->table)) {
                    $maxaux->index = $cl;
                }
            }

            if ($maxaux->index >= $this->result->naux) {
                $this->result->class_action[$this->result->naux++] = $maxaux->table;
            }

            for ($j = 0; $j < count($maxaux->preimage->classes); $j++) {
                $cl = $maxaux->preimage->classes[$j];
                if ($this->result->class2nd[$cl] < 0) {
                    $this->result->class2nd[$cl] = $maxaux->index;
                }
            }

            if (DEBUG) {
                $this->context->debug(sprintf("Selected aux[%d]: (%d) ", $maxaux->index, $maxaux->gain));
                $f = 0;
                for ($j = 0; $j < $this->result->nterms; $j++) {
                    if (!is_vacant($maxaux->table[$j])) {
                        $this->context->debug(sprintf($f++ ? ",%d" : "%d", $maxaux->table[$j]));
                    }
                }
                $this->context->debug(" * ");
                $f = 0;
                for ($j = 0; $j < count($maxaux->preimage->classes); $j++) {
                    $cl = $maxaux->preimage->classes[$j];
                    if ($this->result->class2nd[$cl] === $maxaux->index) {
                        $this->context->debug(sprintf($f++ ? ",%d" : "%d", $cl));
                    }
                }
                $this->context->debug("\n");
            }

            for ($p = $alist; $p != null; $p = $p->next) {
                $this->best_covering($p, $p->preimage);
            }
        }
        if (DEBUG) {
            for ($i = 0; $i < $this->context->nnonleafstates; $i++) {
                if ($this->result->class2nd[$this->result->class_of[$i]] >= 0 && $this->result->class2nd[$this->result->class_of[$i]] !== $this->result->class_of[$i]) {
                    $this->context->debug(sprintf("state %d (class %d): aux[%d]\n", $i, $this->result->class_of[$i], $this->result->class2nd[$this->result->class_of[$i]]));
                } else {
                    $this->context->debug(sprintf("state %d (class %d)\n", $i, $this->result->class_of[$i]));
                }
            }
        }
    }

    protected function best_covering(Auxiliary $aux, Preimage $prim)
    {
        $this->result->resetFrequency();
        $gain = 0;
        for ($i = 0; $i < $this->result->nterms; $i++) {
            $max = 0;
            $maxAction = -1;
            $nvacant = 0;
            for ($j = 0; $j < count($prim->classes); $j++) {
                if ($this->result->class2nd[$prim->classes[$j]] < 0) {
                    $c = $this->result->class_action[$prim->classes[$j]][$i];
                    if ($c > 0 && ++$this->result->frequency[$c] > $max) {
                        $maxAction = $c;
                        $max = $this->result->frequency[$c];
                    } elseif (is_vacant($c)) {
                        $nvacant++;
                    }
                }
            }
            $n = $max - 1 - $nvacant;
            if ($n > 0) {
                $aux->table[$i] = $maxAction;
                $gain += $n;
            } else {
                $aux->table[$i] = CompressResult::VACANT;
            }
        }
        $aux->gain = $gain;
    }

    protected function authodox_table()
    {
        // TODO
        $this->result->ctermindex = array_fill(0, $this->result->nterms, -1);
        $this->result->otermindex = array_fill(0, $this->result->nterms, 0);

        $ncterms = 0;
        for ($j = 0; $j < $this->result->nterms; $j++) {
            if ($j === $this->context->errorToken->code) {
                $this->result->ctermindex[$j] = $ncterms;
                $this->result->otermindex[$ncterms++] = $j;
                continue;
            }
            for ($i = 0; $i < $this->context->nnonleafstates; $i++) {
                if ($this->result->term_action[$i][$j] !== CompressResult::VACANT) {
                    $this->result->ctermindex[$j] = $ncterms;
                    $this->result->otermindex[$ncterms++] = $j;
                    break;
                }
            }
        }

        $cterm_action = array_fill(0, $this->result->naux, array_fill(0, $ncterms, 0));
        for ($i = 0; $i < $this->result->nclasses; $i++) {
            for ($j = 0; $j < $ncterms; $j++) {
                $cterm_action[$i][$j] = $this->result->class_action[$i][$this->result->otermindex[$j]];
            }
        }

        #502

        for ($i = 0; $i < $this->result->nclasses; $i++) {
            if ($this->result->class2nd[$i] >= 0 && $this->result->class2nd[$i] != $i) {
                $table = $this->result->class_action[$this->result->class2nd[$i]];
                for ($j = 0; $j < $ncterms; $j++) {
                    if (!is_vacant($table[$this->result->otermindex[$j]])) {
                        if ($cterm_action[$i][$j] === $table[$this->result->otermindex[$j]]) {
                            $cterm_action[$i][$j] = CompressResult::VACANT;
                        } elseif ($cterm_action[$i][$j] === CompressResult::VACANT) {
                            $cterm_action[$i][$j] = CompressResult::YYDEFAULT;
                        }
                    }
                }
            }
        }

        for ($i = $this->result->nclasses; $i < $this->result->naux; $i++) {
            $cterm_action[$i] = $this->result->class_action[$i];
        }
        $base = [];
        $this->pack_table($cterm_action, $this->result->naux, $ncterms, false, false, $this->result->yyaction, $this->result->yycheck, $base);
        $this->result->yydefault = $this->result->default_act;

        $this->result->yybase = array_fill(0, $this->context->nnonleafstates * 2, 0);
        $this->result->yybasesize = $this->context->nnonleafstates;
        for ($i = 0; $i < $this->context->nnonleafstates; $i++) {
            $cl = $this->result->class_of[$i];
            $this->result->yybase[$i] = $base[$cl];
            if ($this->result->class2nd[$cl] >= 0 && $this->result->class2nd[$cl] != $cl) {
                $this->result->yybase[$i + $this->context->nnonleafstates] = $base[$this->result->class2nd[$cl]];
                if ($i + $this->context->nnonleafstates + 1 > $this->result->yybasesize) {
                    $this->result->yybasesize = $i + $this->context->nnonleafstates + 1;
                }
            }
        }

        $this->result->yybase = array_slice($this->result->yybase, 0, $this->result->yybasesize);

        #642
        $nonterm_transposed = array_fill(0, $this->context->nnonterminals, array_fill(0, $this->context->nnonleafstates, 0));
        foreach ($nonterm_transposed as $j => $_dump) {
            for ($i = 0; $i < $this->context->nnonleafstates; $i++) {
                $nonterm_transposed[$j][$i] = $this->result->nonterm_goto[$i][$j];
                if ($this->result->nonterm_goto[$i][$j] === $this->result->default_goto[$j]) {
                    $nonterm_transposed[$j][$i] = CompressResult::VACANT;
                }
            }
        }

        $this->pack_table($nonterm_transposed, $this->context->nnonterminals, $this->context->nnonleafstates, false, true, $this->result->yygoto, $this->result->yygcheck, $this->result->yygbase);

        $this->result->yygdefault = $this->result->default_goto;

        $this->result->yylhs = [];
        $this->result->yylen = [];
        foreach ($this->context->grams as $gram) {
            // TODO: This is wrong... I think...
            $this->result->yylhs[] = $gram->body[0]->nb;
            $this->result->yylen[] = count($gram->body) - 1;
        }

        $yytranslatesize = 0;
        $minSymbolMap = 256;

        foreach ($this->context->terminals as $term) {
            $value = $term->value;
            if (is_string($value)) {
                $term->value = ord($value);
            } elseif (is_null($value) && substr($term->name, 0, 1) === "'") {
                $term->value = ord($term->name[1]);
            } elseif (!is_int($value)) {
                $term->value = $minSymbolMap++;
            }
            if ($term->value + 1 > $yytranslatesize) {
                $yytranslatesize = $term->value + 1;
            }
        }

        $this->result->yytranslate = array_fill(0, $yytranslatesize, $ncterms);
        $this->result->yyncterms = $ncterms;

        
        for ($i = 0; $i < $this->context->nterminals; $i++) {
            if ($this->result->ctermindex[$i] >= 0) {
                $symbol = $this->context->symbol($i);
                $this->result->yytranslate[$symbol->value] = $this->result->ctermindex[$i];
            }
        }

        $this->result->yyaction = $this->encode_shift_reduce($this->result->yyaction);
        $this->result->yygoto = $this->encode_shift_reduce($this->result->yygoto);
        $this->result->yygdefault = $this->encode_shift_reduce($this->result->yygdefault, $this->context->nnonterminals);
    }

    protected function pack_table(array $transit, int $nrows, int $ncols, bool $dontcare, bool $checkrow, array &$outtable, array &$outcheck, array &$outbase)
    {
        $trow = [];
        for ($i = 0; $i < $nrows; $i++) {
            $trow[] = $p = new TRow($i);
            for ($j = 0; $j < $ncols; $j++) {
                if (!is_vacant($transit[$i][$j])) {
                    if ($p->mini < 0) {
                        $p->mini = $j;
                    }
                    $p->maxi = $j + 1;
                    $p->nent++;
                }
            }
            if ($p->mini < 0) {
                $p->mini = 0;
            }
        }

        usort($trow, [TRow::class, 'compare']);

        if (DEBUG) {
            $this->context->debug("Order:\n");
            for ($i = 0; $i < $nrows; $i++) {
                $this->context->debug(sprintf("%d,", $trow[$i]->index));
            }
            $this->context->debug("\n");
        }

        $poolsize = $nrows * $ncols;
        $actpool = array_fill(0, $poolsize, 0);
        $check = array_fill(0, $poolsize, -1);
        $base = array_fill(0, $nrows, 0);
        $actpoolmax = 0;

        for ($ii = 0; $ii < $nrows; $ii++) {
            $i = $trow[$ii]->index;
            if (vacant_row($transit[$i])) {
                $base[$i] = 0;
                continue;
            }
            for ($h = 0; $h < $ii; $h++) {
                if (eq_row($transit[$trow[$h]->index], $transit[$i])) {
                    $base[$i] = $base[$trow[$h]->index];
                    continue 2;
                }
            }
            for ($j = 0; $j < $poolsize; $j++) {
                $jj = $j;
                $base[$i] = $j - $trow[$ii]->mini;
                if (!$dontcare) {
                    if ($base[$i] === 0) {
                        continue;
                    }
                    for ($h = 0; $h < $ii; $h++) {
                        if ($base[$trow[$h]->index] === $base[$i]) {
                            continue 2;
                        }
                    }
                }

                for ($k = $trow[$ii]->mini; $k < $trow[$ii]->maxi; $k++) {
                    if (!is_vacant($transit[$i][$k])) {
                        if ($jj >= $poolsize) {
                            die("Can't happen");
                        }
                        if ($check[$jj] >= 0 && !($dontcare && $actpool[$jj] === $transit[$i][$k])) {
                            continue 2;
                        }
                    }
                    $jj++;
                }
                break;
            }
            $jj = $j;
            for ($k = $trow[$ii]->mini; $k < $trow[$ii]->maxi; $k++) {
                if (!is_vacant($transit[$i][$k])) {
                    $actpool[$jj] = $transit[$i][$k];
                    $check[$jj] = $checkrow ? $i : $k;
                }
                $jj++;
            }
            if ($jj >= $actpoolmax) {
                $actpoolmax = $jj;
            }
        }

        $outtable = array_slice($actpool, 0, $actpoolmax);
        $outcheck = array_slice($check, 0, $actpoolmax);
        $outbase = $base;
    }
}