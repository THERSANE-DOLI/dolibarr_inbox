<?php
/* Copyright (C) 2026 THERSANE www.thersane.fr
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    inbox/class/inboxpieceinsert.class.php
 * \ingroup inbox
 * \brief   Insert a piece of Dolibarr in a writing (lot A5, 1.83.0): the pieces of the thirdparties of the recipients,
 *          and what a piece gives to the mail: a line that recalls it, the table of its lines, its PDF, the links of
 *          the online signature and payment of Dolibarr
 */

require_once __DIR__.'/inboxelements.class.php';
require_once __DIR__.'/inboxcontactmatcher.class.php';

/**
 * Customer pieces only (proposals, orders, invoices); the rights of reading of the user on each piece. Written in the
 * language of the thirdparty of the piece, as its PDF.
 */
class InboxPieceInsert
{
	/** Types: table, column of the date, element of the entity, modulepart of the documents, type of the links */
	const TYPES = array(
		'propal' => array('table' => 'propal', 'date' => 'datep', 'entity' => 'propal', 'kind' => 'Proposal', 'lang' => 'propal'),
		'commande' => array('table' => 'commande', 'date' => 'date_commande', 'entity' => 'commande', 'kind' => 'Order', 'lang' => 'orders'),
		'facture' => array('table' => 'facture', 'date' => 'datef', 'entity' => 'invoice', 'kind' => 'Invoice', 'lang' => 'bills'),
	);

	/** Pieces found per type */
	const MAX = 15;

	/** @var DoliDB */
	protected $db;

	/** @var string */
	public $error = '';

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct(DoliDB $db)
	{
		$this->db = $db;
	}

	/**
	 * Thirdparties of addresses: the address itself, else its domain (never a public or shared one)
	 *
	 * @param string[] $emails Addresses
	 * @return int[]
	 */
	public function socsOf(array $emails)
	{
		$matcher = new InboxContactMatcher($this->db);
		$out = array();
		foreach (array_slice(array_unique(array_map('strtolower', array_map('trim', $emails))), 0, 20) as $e) {
			if (!isValidEmail($e)) {
				continue;
			}
			$soc = (int) $matcher->match($e)['fk_soc'] ?: $matcher->matchDomain($e);
			if ($soc > 0) {
				$out[$soc] = $soc;
			}
		}

		return array_values($out);
	}

	/**
	 * Pieces to insert: of the thirdparties given (or all), searched by reference or thirdparty, the latest first
	 *
	 * @param User   $user User
	 * @param int[]  $socs Thirdparties (empty and $all false: nothing)
	 * @param string $term Reference or name searched
	 * @param bool   $all  All the thirdparties
	 * @return array<int,array{type:string,id:int,ref:string,kind:string,icon:string,soc:string,date:int,total_ht:float,status:string}>
	 */
	public function search(User $user, array $socs, $term, $all)
	{
		global $langs;

		$term = trim((string) $term);
		if (!$all && !$socs) {
			return array();
		}
		$p = $this->db->prefix();
		$out = array();
		foreach (self::TYPES as $type => $t) {
			if (!InboxElements::enabled($type) || !InboxElements::canReadType($user, $type)) {
				continue;
			}
			$sql = "SELECT o.rowid, o.ref, o.".$t['date']." as d, o.total_ht, s.nom FROM ".$p.$t['table']." as o LEFT JOIN ".$p."societe as s ON s.rowid = o.fk_soc"
				." WHERE o.entity IN (".getEntity($t['entity']).") AND o.fk_statut >= 0";
			if (!$all) {
				$sql .= " AND o.fk_soc IN (".implode(',', array_map('intval', $socs)).")";
			}
			if ($term !== '') {
				$like = $this->db->escape($this->db->escapeforlike($term));
				$sql .= " AND (o.ref LIKE '%".$like."%' OR s.nom LIKE '%".$like."%')";
			}
			$rows = $this->db->getRows($sql." ORDER BY o.".$t['date']." DESC, o.rowid DESC LIMIT ".(self::MAX * 2));
			$n = 0;
			foreach (is_array($rows) ? $rows : array() as $r) {
				if ($n >= self::MAX || !InboxElements::canRead($user, $type, (int) $r->rowid)) {
					continue;
				}
				$n++;
				$out[] = array('type' => $type, 'id' => (int) $r->rowid, 'ref' => (string) $r->ref, 'kind' => $langs->transnoentities($t['kind']), 'icon' => InboxElements::TYPES[$type]['icon'] ?? 'document',
					'soc' => (string) $r->nom, 'date' => $r->d ? (int) $this->db->jdate($r->d) : 0, 'total_ht' => (float) $r->total_ht, 'total' => price((float) $r->total_ht, 0, $langs, 1, -1, -1, 'auto'));
			}
		}
		usort($out, function ($a, $b) {
			return $b['date'] <=> $a['date'];
		});

		return $out;
	}

	/**
	 * A piece readable by the user, loaded with its lines and thirdparty
	 *
	 * @param User   $user User
	 * @param string $type Type
	 * @param int    $id   Piece
	 * @return CommonObject|null
	 */
	public function load(User $user, $type, $id)
	{
		if (!isset(self::TYPES[$type]) || !InboxElements::canRead($user, $type, (int) $id)) {
			return null;
		}
		$o = fetchObjectByElement((int) $id, $type);
		if (!is_object($o) || empty($o->id)) {
			return null;
		}
		if (empty($o->lines) && method_exists($o, 'fetch_lines')) {
			$o->fetch_lines();
		}
		if (method_exists($o, 'fetch_thirdparty')) {
			$o->fetch_thirdparty();
		}

		return $o;
	}

	/**
	 * What a piece may give to the mail
	 *
	 * @param CommonObject $o    Piece (load())
	 * @param string       $type Type
	 * @param string       $pdf  Name of its PDF, '' when none
	 * @return array{sign:bool,pay:bool,pdf:string,remain:string}
	 */
	public function options($o, $type, $pdf)
	{
		$sign = $type === 'propal' && getDolGlobalString('PROPOSAL_ALLOW_ONLINESIGN') && (int) $o->status === 1;
		$remain = 0.0;
		if ($type === 'facture') {
			$remain = (float) $o->total_ttc - (float) $o->getSommePaiement() - (float) $o->getSumCreditNotesUsed() - (float) $o->getSumDepositsUsed();
		} elseif ($type === 'commande') {
			$remain = (float) $o->total_ttc;
		}
		$online = isModEnabled('stripe') || isModEnabled('paypal') || isModEnabled('paybox');
		$pay = $online && (($type === 'facture' && (int) $o->status >= 1 && empty($o->paye) && $remain > 0) || ($type === 'commande' && (int) $o->status >= 1 && empty($o->billed) && $remain > 0));

		return array('sign' => (bool) $sign, 'pay' => (bool) $pay, 'pdf' => (string) $pdf, 'remain' => $remain > 0 ? price($remain, 0, null, 1, -1, -1, (string) ($o->multicurrency_code ?: 'auto')) : '');
	}

	/**
	 * Language of the thirdparty of the piece (as its PDF), else the one of the user
	 *
	 * @param CommonObject $o Piece
	 * @return Translate
	 */
	protected function langsOf($o)
	{
		global $langs;

		$code = is_object($o->thirdparty ?? null) && (string) $o->thirdparty->default_lang !== '' ? (string) $o->thirdparty->default_lang : $langs->defaultlang;
		$out = new Translate('', $GLOBALS['conf']);
		$out->setDefaultLang($code);
		$out->loadLangs(array('main', 'companies', 'propal', 'orders', 'bills', 'products', 'inbox@inbox'));

		return $out;
	}

	/**
	 * HTML given to the writing: a line that recalls the piece, the table of its lines, the online links asked
	 *
	 * @param CommonObject        $o    Piece (load())
	 * @param string              $type Type
	 * @param array<string,bool>  $ask  summary, lines, sign, pay
	 * @return string
	 */
	public function html($o, $type, array $ask)
	{
		$l = $this->langsOf($o);
		$cur = (string) ($o->multicurrency_code ?: 'auto');
		$money = function ($v) use ($l, $cur) {
			return price((float) $v, 0, $l, 1, -1, -1, $cur);
		};
		$date = (int) ($type === 'propal' ? $o->date : ($type === 'commande' ? $o->date_commande ?? $o->date : $o->date));
		$out = '';
		if (!empty($ask['summary'])) {
			$text = $l->transnoentities('InboxPieceRecall', $l->transnoentities(self::TYPES[$type]['kind']), (string) $o->ref, dol_print_date($date, 'day', 'tzuserrel', $l),
				$money($o->total_ht), $money($o->total_ttc));
			$out .= '<p>'.dol_escape_htmltag($text).'</p>';
		}
		if (!empty($ask['lines'])) {
			$out .= $this->linesTable($o, $l, $money);
		}
		if (!empty($ask['sign'])) {
			require_once DOL_DOCUMENT_ROOT.'/core/lib/signature.lib.php';
			$url = getOnlineSignatureUrl(0, 'proposal', (string) $o->ref, 1, $o);
			$out .= '<p><a href="'.dol_escape_htmltag($url).'">'.dol_escape_htmltag($l->transnoentities('InboxPieceSignLink', (string) $o->ref)).'</a></p>';
		}
		if (!empty($ask['pay'])) {
			require_once DOL_DOCUMENT_ROOT.'/core/lib/payments.lib.php';
			$url = getOnlinePaymentUrl(0, $type === 'facture' ? 'invoice' : 'order', (string) $o->ref);
			$out .= '<p><a href="'.dol_escape_htmltag($url).'">'.dol_escape_htmltag($l->transnoentities('InboxPiecePayLink', (string) $o->ref)).'</a></p>';
		}

		return $out;
	}

	/**
	 * Lines of a piece as a table a mail software reads (styles in the cells): description, quantity, unit price,
	 * discount, total excluding tax; the titles of sections as a line of their own; the totals under it
	 *
	 * @param CommonObject $o     Piece
	 * @param Translate    $l     Language of the thirdparty
	 * @param callable     $money Amount written
	 * @return string
	 */
	protected function linesTable($o, $l, $money)
	{
		$th = 'style="text-align:%s;padding:6px 8px;border:1px solid #d0d5dd;background:#f3f4f6;font-weight:600"';
		$td = 'style="text-align:%s;padding:6px 8px;border:1px solid #d0d5dd;vertical-align:top"';
		$head = array(array('Description', 'left'), array('Qty', 'right'), array('PriceUHT', 'right'), array('ReductionShort', 'right'), array('TotalHT', 'right'));
		$html = '<table style="border-collapse:collapse;width:100%"><thead><tr>';
		foreach ($head as $h) {
			$html .= '<th '.sprintf($th, $h[1]).'>'.dol_escape_htmltag($l->transnoentities($h[0])).'</th>';
		}
		$html .= '</tr></thead><tbody>';
		$discount = false;
		foreach ((array) $o->lines as $line) {
			$discount = $discount || (float) $line->remise_percent > 0;
		}
		foreach ((array) $o->lines as $line) {
			$label = trim((string) ($line->product_ref ?? '') !== '' ? $line->product_ref.' - '.($line->product_label ?: $line->libelle ?? '') : '');
			$desc = trim(dol_string_nohtmltag((string) $line->desc, 1));
			// A title or a subtotal of a module of sections: a line of its own
			if ((int) $line->special_code !== 0 && (int) $line->product_type === 9) {
				$html .= '<tr><td colspan="5" '.sprintf($td, 'left').'><b>'.dol_escape_htmltag($desc !== '' ? $desc : $label).'</b></td></tr>';
				continue;
			}
			$text = '<b>'.dol_escape_htmltag($label !== '' ? $label : dol_trunc($desc, 120, 'right', 'UTF-8', 1)).'</b>';
			if ($label !== '' && $desc !== '' && $desc !== trim((string) ($line->product_label ?? ''))) {
				$text .= '<br>'.nl2br(dol_escape_htmltag(dol_trunc($desc, 300, 'right', 'UTF-8', 1)));
			}
			$html .= '<tr><td '.sprintf($td, 'left').'>'.$text.'</td>'
				.'<td '.sprintf($td, 'right').'>'.dol_escape_htmltag(price2num((float) $line->qty, 'MS') + 0).'</td>'
				.'<td '.sprintf($td, 'right').'>'.dol_escape_htmltag($money($line->subprice)).'</td>'
				.'<td '.sprintf($td, 'right').'>'.($discount && (float) $line->remise_percent > 0 ? dol_escape_htmltag(price2num((float) $line->remise_percent, 2) + 0).' %' : '').'</td>'
				.'<td '.sprintf($td, 'right').'>'.dol_escape_htmltag($money($line->total_ht)).'</td></tr>';
		}
		$html .= '</tbody><tfoot>';
		foreach (array(array('TotalHT', $o->total_ht), array('TotalVAT', $o->total_tva), array('TotalTTC', $o->total_ttc)) as $tot) {
			$html .= '<tr><td colspan="4" '.sprintf($td, 'right').'>'.dol_escape_htmltag($l->transnoentities($tot[0])).'</td><td '.sprintf($td, 'right').'><b>'.dol_escape_htmltag($money($tot[1])).'</b></td></tr>';
		}

		return $html.'</tfoot></table><p></p>';
	}
}
