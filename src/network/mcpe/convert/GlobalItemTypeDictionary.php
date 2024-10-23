<?php

/*
 *
 *  ____            _        _   __  __ _                  __  __ ____
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author PocketMine Team
 * @link http://www.pocketmine.net/
 *
 *
 */

declare(strict_types=1);

namespace pocketmine\network\mcpe\convert;

use pocketmine\data\bedrock\BedrockDataFiles;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\network\mcpe\protocol\serializer\ItemTypeDictionary;
use pocketmine\network\mcpe\protocol\types\ItemTypeEntry;
use pocketmine\player\Player;
use pocketmine\utils\AssumptionFailedError;
use pocketmine\utils\Filesystem;
use pocketmine\utils\SingletonTrait;
use function is_array;
use function is_bool;
use function is_int;
use function is_string;
use function json_decode;
use function str_replace;

final class GlobalItemTypeDictionary{
	use SingletonTrait;

	private const PATHS = [
		ProtocolInfo::CURRENT_PROTOCOL => "",
		ProtocolInfo::PROTOCOL_1_21_30 => "-1.21.30",
		ProtocolInfo::PROTOCOL_1_21_20 => "-1.21.20",
		ProtocolInfo::PROTOCOL_1_21_2 => "-1.21.2",
		ProtocolInfo::PROTOCOL_1_21_0 => "-1.21.2",
		ProtocolInfo::PROTOCOL_1_20_80 => "-1.20.80",
		ProtocolInfo::PROTOCOL_1_20_70 => "-1.20.70",
		ProtocolInfo::PROTOCOL_1_20_60 => "-1.20.60",
		ProtocolInfo::PROTOCOL_1_20_50 => "-1.20.50",
		ProtocolInfo::PROTOCOL_1_20_40 => "-1.20.40",
		ProtocolInfo::PROTOCOL_1_20_10 => "-1.20.10",
		ProtocolInfo::PROTOCOL_1_20_0 => "-1.20.0"
	];

	private static function make() : self{
		$dictionaries = [];

		foreach (self::PATHS as $protocolId => $path){
			$data = Filesystem::fileGetContents(str_replace('.json', $path . '.json', BedrockDataFiles::REQUIRED_ITEM_LIST_JSON));
			$table = json_decode($data, true);
			if(!is_array($table)){
				throw new AssumptionFailedError("Invalid item list format");
			}

			$params = [];
			foreach($table as $name => $entry){
				if(!is_array($entry) || !is_string($name) || !isset($entry["component_based"], $entry["runtime_id"]) || !is_bool($entry["component_based"]) || !is_int($entry["runtime_id"])){
					throw new AssumptionFailedError("Invalid item list format");
				}
				$params[] = new ItemTypeEntry($name, $entry["runtime_id"], $entry["component_based"]);
			}

			$dictionaries[$protocolId] = new ItemTypeDictionary($params);
		}

		return new self($dictionaries);
	}

	/**
	 * @param ItemTypeDictionary[] $dictionaries
	 */
	public function __construct(private array $dictionaries){}

	public static function getDictionaryProtocol(int $protocolId) : int{
		if($protocolId === ProtocolInfo::PROTOCOL_1_20_30){
			return ProtocolInfo::PROTOCOL_1_20_40;
		}

		return $protocolId;
	}

	/**
	 * @param Player[] $players
	 *
	 * @return Player[][]
	 */
	public static function sortByProtocol(array $players) : array{
		$sortPlayers = [];

		foreach($players as $player){
			$dictionaryProtocol = self::getDictionaryProtocol($player->getNetworkSession()->getProtocolId());

			if(isset($sortPlayers[$dictionaryProtocol])){
				$sortPlayers[$dictionaryProtocol][] = $player;
			}else{
				$sortPlayers[$dictionaryProtocol] = [$player];
			}
		}

		return $sortPlayers;
	}

	/**
	 * @return  ItemTypeDictionary[] $dictionaries
	 */
	public function getDictionaries() : array{ return $this->dictionaries; }

	public function getDictionary(int $dictionaryId = ProtocolInfo::CURRENT_PROTOCOL) : ItemTypeDictionary{ return $this->dictionaries[$dictionaryId] ?? $this->dictionaries[ProtocolInfo::CURRENT_PROTOCOL]; }
}
