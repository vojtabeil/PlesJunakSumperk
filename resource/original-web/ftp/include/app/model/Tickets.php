<?php

namespace Model;

include "QRPlatba.php";

use Nette;
use Nette\Mail\SmtpMailer;

class Tickets extends Nette\NetteObject {
    
    const RESERVED = "reserved";
    const PAID = "paid";
    const REJECTED = "rejected";
    
    
    /** @var Nette\Datatbase\Connection */
    private $db;

    private $presenter;
    
    /** @var string */
    private $table = "ticket";
    
    private function table() {
        return $this->db->table($this->table);
    }
    
    /** @param Nette\Datatbase\Connection */
    public function __construct(Nette\Database\Connection $db, Nette\Application\Application $app) {
        $this->db = $db;
        $this->presenter = $app->presenter;
    }    
    
    /**
     * @param string
     * @param string
     * @param int
     * @return Nette\Database\Table\ActiveRow
     */
    public function orderTickets($name, $email, $count, $alcoholStripCount, $loweredCount, $price, $loweredPrice, $paymentMethod, $from) {

        $data = array(
            'name' => $name,
            'email' => $email,
            'count' => $count + $loweredCount,
            'alcohol_strip_count' => $alcoholStripCount,
            'state' => self::RESERVED,
            'confirmed' => FALSE,
            'variable_symbol' => $this->getVariableSymbol(),
            'price' => $count * $price + $loweredCount * $loweredPrice,
            'reserved' => new \DateTime,
            'payment_method' => $paymentMethod,
        );

        $row = $this->table()->insert($data);

        if($row) {
            if($paymentMethod == "online") {
                $this->sendEmail("Rezervace lístků na skautský ples", $from,"/../email/info.latte", $row, $row->email);        
            } else {
                $this->sendEmail("Rezervace lístků na skautský ples", $from,"/../email/cash.latte", $row, $row->email);                        
            }
            $this->sendEmail("Ples - další {$row->count} rezervace", $from,"/../email/notification.latte", $row, "valentafp@gmail.com");        
        }

        return $row;
    }

    public function countOfReservations($email) {        
        return $this->table()->where("email", $email)->where("state <> ?", "rejected")->sum("count");
    }

    private function sendEmail($subject, $from, $template, $row, $to) {
        $tpl = new Nette\Templating\FileTemplate(__DIR__ . $template);
        $tpl->registerFilter(new Nette\Latte\Engine);
        $tpl->ticket = $row;
        $tpl->presenter = $this->presenter;
        $tpl->qrUrl = $this->qrPaymetImageUrl($row->id);
        $message = new Nette\Mail\Message;
        $message
            ->addTo($to)
            ->setFrom($from)
            ->setSubject($subject)
            ->setHtmlBody($tpl);

        $mailer = new SmtpMailer(array(
            "host" => "smtp.seznam.cz",
            "secure" => "ssl",
            "port" => 465,
            "username" => "ples@junak-sumperk.cz",
            "password" => 'dJM5kPh4H#$J',
        ));

        $mailer->send($message);
    }
    
    /**
     * @param Nette\Database\Table\ActiveRow
     * @return int|FALSE
     */
    public function confirmTicketPayment($id, $from) {
        $row = $this->table()->where("id", $id)->fetch();
        $row->state = self::PAID;
        
        if($row->update()) {
            $this->sendEmail("Potvrzení zaplacení lístků na skautský ples", $from,"/../email/confirm.latte", $row, $row->email);        
            return TRUE;
        }

        return FALSE;
    }
    
    /**
     * @param Nette\Database\Table\ActiveRow
     * @return int|FALSE
     */
    public function revertOk($id, $from) {
        $row = $this->table()->where("id", $id)->fetch();
        $row->state = self::RESERVED;
        
        if($row->update()) {
            $this->sendEmail("Omlouváme se za chybnou informaci - šumperský skautský ples", $from,"/../email/not_ok.latte", $row, $row->email);        
            return TRUE;
        }

        return FALSE;
    }
    
    /**
     * @param Nette\Database\Table\ActiveRow
     * @return int|FALSE
     */
    public function reject($id, $from) {
        $row = $this->table()->where("id", $id)->fetch();
        $row->state = self::REJECTED;
        
        if($row->update()) {
            $this->sendEmail("Rezervace lístků na skautský ples zamítnuta", $from,"/../email/reservation_removed.latte", $row, $row->email);        
            return TRUE;
        }

        return FALSE;
    }
    
    /**
     * @param Nette\Database\Table\ActiveRow
     * @return int|FALSE
     */
    public function togglePaymentMethod($id) {
        $row = $this->table()->where("id", $id)->fetch();
        $row->payment_method = $row->payment_method === "cash" ? "online" : "cash";
        return (bool) $row->update();
    }


    /** @return Nette\Database\Table\Selection */
    public function getAll() {
        return $this->table();
    }
    
    
    /** @return Nette\Database\Table\ActiveRow */
    public function get($id) {
        return $this->table()->where('id', $id)->fetch();
    }
    
    
    /** @return Nette\Database\Table\ActiveRow */
    public function getBought() {
        return $this->table()->where('state', self::PAID);
    }
    
    /** @return Nette\Database\Table\ActiveRow */
    public function getReserved() {
        return $this->table()->where('state', self::RESERVED);
    }
    
    /** @return Nette\Database\Table\ActiveRow */
    public function getRejected() {
        return $this->table()->where('state', self::REJECTED);
    }

    /** @return Nette\Database\Table\ActiveRow */
    public function getNonRejected() {
        return $this->table()->where('state != ?', self::REJECTED);
    }

    const PREFIX = "00";
    public function getVariableSymbol() {
        $maxId = $this->table()->max("id");
        return self::PREFIX . ($maxId + 1);
    }

    public function getBoughtTicketsCount() {
        return $this->table()->where("state", self::PAID)->sum("count");
    }

    public function getPendingTicketsCount() {
        return $this->table()->where("state", self::RESERVED)->sum("count");
    }
    public function qrPaymetImageUrl($id){
        $ticket = $this->get($id);
        if(!$ticket){
            return "";
        }
        $platba = new \QRPlatba('2501895120/2010', $ticket->price);
        $platba->setMessage("Platba za lístky na ples");
        $platba->setVariableSym($ticket->variable_symbol);
        return 'https://chart.apis.google.com/chart?cht=qr&chs=200x200&choe=UTF-8&chld=H%7C0&chl=' . urlencode($platba);
    }

}