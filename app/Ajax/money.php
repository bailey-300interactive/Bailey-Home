<?php

/**
 * Money actions: save / get / delete a transaction, guess a category, set a category inline
 */

define('ROOT', realpath(__DIR__ . '/../..') . '/');
require(ROOT . 'app/init.php');

App::RequireLogin();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(array('result' => false, 'message' => 'POST only'), 405);
}
App::CheckCsrf();
$action = post('action');

try {
    switch ($action) {
        case 'save':
            $in = $_POST;
            if (empty($in['client_id'])) {
                $in['billable'] = 0;
            }
            $receipt = Finance::StoreReceipt('receipt');
            $id = Finance::SaveTransaction(post_id('id'), $in);
            if ($receipt) {
                $old = val('SELECT receipt FROM fin_transactions WHERE id = ?', array($id));
                if ($old) {
                    @unlink(STORAGE . 'receipts/' . basename($old));
                }
                run('UPDATE fin_transactions SET receipt = ? WHERE id = ?', array($receipt, $id));
            }
            if (post_bool('remove_receipt') && !$receipt) {
                $old = val('SELECT receipt FROM fin_transactions WHERE id = ?', array($id));
                if ($old) {
                    @unlink(STORAGE . 'receipts/' . basename($old));
                    run('UPDATE fin_transactions SET receipt = NULL WHERE id = ?', array($id));
                }
            }
            // "Always categorize like this" makes a rule
            if (post_bool('make_rule') && post('payee') !== '' && post_id('category_id')) {
                if (!val('SELECT 1 FROM fin_rules WHERE match_text = ?', array(post('payee')))) {
                    insert('fin_rules', array('match_text' => mb_substr(post('payee'), 0, 128), 'category_id' => post_id('category_id'), 'created_at' => App::UtcNow()));
                }
            }
            $kind = post('kind', 'expense');
            json_out(array('result' => true, 'id' => $id, 'message' => ($kind === 'transfer' ? 'Transfer' : ucfirst($kind)) . ' of ' . money(abs(parse_money(post('amount')))) . ' saved'));

        case 'get':
            $t = row('SELECT t.*, (SELECT o.account_id FROM fin_transactions o WHERE o.transfer_group = t.transfer_group AND o.id <> t.id LIMIT 1) AS other_account FROM fin_transactions t WHERE t.id = ?', array(post_id('id')));
            if (!$t) {
                throw new RuntimeException('Transaction not found.');
            }
            if ($t['kind'] === 'transfer' && $t['amount'] > 0) {
                // show transfers from the sending side
                $t['to_account_id'] = $t['account_id'];
                $t['account_id'] = $t['other_account'];
            } elseif ($t['kind'] === 'transfer') {
                $t['to_account_id'] = $t['other_account'];
            }
            $t['amount_abs'] = number_format(abs($t['amount']), 2, '.', '');
            json_out(array('result' => true, 'tx' => $t));

        case 'delete':
            Finance::DeleteTransaction(post_id('id'));
            json_out(array('result' => true, 'message' => 'Deleted'));

        case 'guess':
            json_out(array('result' => true, 'category_id' => Finance::GuessCategory(post('payee'))));

        case 'categorize':
            $id = post_id('id');
            run("UPDATE fin_transactions SET category_id = ? WHERE id = ? AND kind <> 'transfer'", array(post_id('category_id'), $id));
            $n = 0;
            if (post_bool('all_from_payee')) {
                $payee = val('SELECT payee FROM fin_transactions WHERE id = ?', array($id));
                if ($payee) {
                    $n = run("UPDATE fin_transactions SET category_id = ? WHERE payee = ? AND category_id IS NULL AND kind <> 'transfer'", array(post_id('category_id'), $payee));
                    if (!val('SELECT 1 FROM fin_rules WHERE match_text = ?', array($payee))) {
                        insert('fin_rules', array('match_text' => $payee, 'category_id' => post_id('category_id'), 'created_at' => App::UtcNow()));
                    }
                }
            }
            json_out(array('result' => true, 'also' => $n));
    }
} catch (RuntimeException $e) {
    json_out(array('result' => false, 'message' => $e->getMessage()));
}

json_out(array('result' => false, 'message' => 'Action not recognized'));
