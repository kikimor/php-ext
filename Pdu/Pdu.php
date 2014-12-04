<?php
/**
 * This class generates PDU for sending SMS messages.
 *
 * Class support:
 * - Generates Unicode messages.
 * - Generates longer messages. More 160 chars in ASCII or 70 chars in Unicode.
 * - Automatic detection of character encoding when sending (ASCII or Unicode).
 * - Generates PDU as push (flash) message.
 *
 * Usage:
 * $pdu = new Pdu();
 * $pdu->number = '+79026000000'; // International phone format.
 * $pdu->text - 'test message'; // Text only in UTF-8 encoding.
 * $pduCommands = $pdu->generate(); // Method generate() return a array of PDU commands.
 *
 * Author: kikimor <i@kikimor.ru>
 * @link: https://github.com/kikimor/php-ext/pdu
 * Version: 1.0
 */
class Pdu
{
	/**
	 * Phone number. International format: +79026000000 or 79026000000.
	 * @var string
	 */
	public $number;

	/**
	 * Text of message.
	 * In UTF-8 charset only.
	 * @var string
	 */
	public $message;

	/**
	 * Request a delivery report (not tested).
	 * @var bool
	 */
	public $report = false;

	/**
	 * Generate PDU as push (flash) message.
	 * @var bool
	 */
	public $flash = false;

	/**
	 * For internal usage only. Don't change.
	 */
	const MESSAGE_CHARSET = 'UTF-8';

	/**
	 * Array of PDU commands.
	 * @return array
	 */
	public function generate()
	{
		if ($this->isLongMessage()) {
			$maxMessageLength = $this->isUnicodeMessage() ? 67 : 152;
		} else {
			$maxMessageLength = $this->isUnicodeMessage() ? 70 : 160;
		}
		$messageLength = mb_strlen($this->message, self::MESSAGE_CHARSET);
		$ied1 = rand(1,255);
		$command = [];
		for ($part = 1; $part <= ceil($messageLength / $maxMessageLength); $part++) {
			$message = mb_substr($this->message, ($part-1) * $maxMessageLength, $maxMessageLength, self::MESSAGE_CHARSET);
			$command[] =
				$this->getSca() .
				$this->pduType() .
				$this->tpMr($part) .
				$this->tpDa() .
				$this->tpPid() .
				$this->tpDcs() .
				$this->tpVp() .
				$this->tpUdl($message) .
				($this->isLongMessage() ? $this->tpUdh($ied1, $part) : '') .
				$this->tpUd($message);
		}
		return $command;
	}

	/**
	 * @return bool
	 */
	public function isLongMessage()
	{
		$maxMessageLength = $this->isUnicodeMessage() ? 70 : 160;
		return mb_strlen($this->message, self::MESSAGE_CHARSET) > $maxMessageLength;
	}

	/**
	 * Service Center Address.
	 * @return string
	 */
	protected function getSca()
	{
		return '00'; // Using SIM card settings.
	}

	/**
	 * PDU flags.
	 * @return string
	 */
	protected function pduType()
	{
		$config = [
			0, // TP-RP - Reply path.
			(int)$this->isLongMessage(), // TP-UDHI. User data header indicator.
			(int)$this->report, // TP-SRR. Status report request.
			0, // TP-RD. Reject duplicates.
			0,0, // TP-VPF. Validity Period Format.
			0,1 // TP-MTI. Output message.
		];

		return $this->pack($config);
	}

	/**
	 * TP-Message-Reference.
	 * @param int $part
	 * @return string
	 */
	protected function tpMr($part = 1)
	{
		return sprintf('%02X', $part - 1);
	}

	/**
	 * TP-Destination-Address.
	 * @return string
	 */
	protected function tpDa()
	{
		$this->number = $number = preg_replace('/[^\d]/u', '', $this->number);
		$numberLength = strlen($number);

		if ($numberLength % 2 !== 0) {
			$number .= 'F';
		}

		for ($i = 0; $i < strlen($number); $i = $i+2) {
			$first = $number[$i];
			$number[$i] = $number[$i+1];
			$number[$i+1] = $first;
		}

		$phoneFormat = 91; // International format.

		return sprintf('%02X', $numberLength) . $phoneFormat . $number;
	}

	/**
	 * TP-Protocol ID.
	 * @return string
	 */
	protected function tpPid()
	{
		return '00';
	}

	/**
	 * TP-Data-Coding-Scheme.
	 * @return string
	 */
	protected function tpDcs()
	{
		return (int)$this->flash . ($this->isUnicodeMessage() ? 8 : 0);
	}

	/**
	 * TP-Validity-Period.
	 * Expire period.
	 */
	protected function tpVp()
	{
		return '';
	}

	/**
	 * TP-User-Data-Length.
	 * @param string $message
	 * @return string
	 */
	protected function tpUdl($message)
	{
		return sprintf('%02X', $this->messageSize($message) +
			($this->isLongMessage() ? ($this->isUnicodeMessage() ? 3 * 2 : 8) : 0));
	}

	/**
	 * @param string $ied1
	 * @param int $part
	 * @return string
	 */
	protected function tpUdh($ied1, $part)
	{
		$maxMessageLength = $this->isUnicodeMessage() ? 67 : 152;
		$udh = [
			$this->isUnicodeMessage() ? '05' : '06', // Length of User Data Header (UDL), in this case 6.
			$this->isUnicodeMessage() ? '00' : '08', // IEI. Define IED1 size (1 (00) or 2 (08) byte).
			$this->isUnicodeMessage() ? '03' : '04', // IEDL. Length of the header. If IEI = 00 then 03, IEI = 08 then 04
			sprintf($this->isUnicodeMessage() ? '%02X' : '%04X', $ied1), // Reference number.
			sprintf('%02X', ceil(mb_strlen($this->message, self::MESSAGE_CHARSET) / $maxMessageLength)), // Count of parts.
			sprintf('%02X', $part), // Part of message.
		];
		return implode($udh);
	}

	/**
	 * TP-User-Data.
	 * @param string $message
	 * @return string
	 */
	protected function tpUd($message)
	{
		if ($this->isUnicodeMessage()) {
			$hex = strtoupper(bin2hex(iconv('UTF-8', 'UCS-2', $message)));
			for ($i = 0; $i<strlen($hex); $i = $i + 4) {
				$first = [
					0 => $hex[$i],
					1 => $hex[$i+1],
				];
				$hex[$i] = $hex[$i+2];
				$hex[$i+1] = $hex[$i+3];
				$hex[$i+2] = $first[0];
				$hex[$i+3] = $first[1];
			}
			return $hex;
		} else {
			return $this->bit7ToHex($this->strTo7Bit($message));
		}
	}

	/**
	 * @param array $binary
	 * @return string
	 */
	protected function pack(array $binary)
	{
		return sprintf('%02X', bindec(implode($binary)));
	}

	/**
	 * @return bool
	 */
	protected function isUnicodeMessage()
	{
		return strlen($this->message) != mb_strlen($this->message, self::MESSAGE_CHARSET);
	}

	/**
	 * Bytes count.
	 * @param string $message
	 * @return int
	 */
	protected function messageSize($message)
	{
		if ($this->isUnicodeMessage()) {
			return mb_strlen($message, self::MESSAGE_CHARSET) * 2;
		} else {
			return strlen($message);
		}
	}

	/**
	 * @param string $input
	 * @param int $length
	 * @return string
	 */
	private function asc2Bin($input, $length=8) {

		$bin_out = '';
		// Loop through every character in the string
		for($charCount=0; $charCount < strlen($input); $charCount++) {
			$charAscii = ord($input{$charCount}); // ascii value of character
			$charBinary = decbin($charAscii); // decimal to binary
			$charBinary = str_pad($charBinary, $length, '0', STR_PAD_LEFT);
			$bin_out .= $charBinary;
		}

		return $bin_out;
	}

	/**
	 * @param string $message
	 * @return array
	 */
	private function strTo7Bit($message) {
		$message = trim($message);
		$length = strlen( $message );
		$i = 1;
		$bitArray = [];

		// Loop through every character in the string
		while ($i <= $length) {
			// Convert this character to a 7 bits value and insert it into the array
			$bitArray[] = $this->asc2Bin( substr( $message ,$i-1,1) ,7);
			$i++;
		}

		return $bitArray;
	}

	/**
	 * @param string $bin
	 * @param bool $padding
	 * @param bool $uppercase
	 * @return string
	 */
	private function bit8ToHex($bin, $padding=false, $uppercase=true) {
		$hex = '';
		// Last item for counter (for-loop)
		$last = strlen($bin)-1;
		// Loop for every item
		for($i=0; $i<=$last; $i++) {
			$hex += $bin[$last-$i] * pow(2,$i);
		}

		// Convert from decimal to hexadecimal
		$hex = dechex($hex);
		// Add a 0 (zero) if there is only 1 value returned, like 'F'
		if($padding && strlen($hex) < 2 ) {
			$hex = '0'.$hex;
		}

		// If we want the output returned as UPPERCASE do this
		if($uppercase) {
			$hex = strtoupper($hex);
		}

		return $hex;
	}

	/**
	 * @param string $bits
	 * @return string
	 */
	private function bit7ToHex($bits) {

		$i = 0;
		$hexOutput = '';
		$running = true;

		// For every 7 bits character array item
		while($running) {

			if(count($bits)==$i+1) {
				$running = false;
			}

			$value = $bits[$i];

			if($value=='') {
				$i++;
				continue;
			}

			// Convert the 7 bits value to the 8 bits value
			// Merge a part of the next array element and a part of the current one

			// Default: new value is current value
			$new = $value;

			if(isset($bits[$i+1])) {
				// There is a next array item so make it 8 bits
				$neededChar = 8 - strlen($value);
				// Get the char;s from the next array item
				$charFromNext = substr($bits[$i+1], -$neededChar);
				// Remove used bit's from next array item
				$bits[$i+1] = substr($bits[$i+1], 0, strlen($bits[$i+1])-$neededChar );
				// New value is characters from next value and current value
				$new = $charFromNext.$value;
			}

			if($new!='') {
				// Always make 8 bits
				$new = str_pad($new, 8, '0', STR_PAD_LEFT);
				// The 8 bits to hex conversion
				$hexOutput .= $this->bit8ToHex($new, true);
			}

			$i++;
		}

		return $hexOutput;
	}
}