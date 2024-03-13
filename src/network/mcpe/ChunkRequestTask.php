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

namespace pocketmine\network\mcpe;

use pocketmine\network\mcpe\compression\Compressor;
use pocketmine\network\mcpe\convert\GlobalItemTypeDictionary;
use pocketmine\network\mcpe\convert\RuntimeBlockMapping;
use pocketmine\network\mcpe\protocol\serializer\PacketSerializer;
use pocketmine\network\mcpe\protocol\types\DimensionIds;
use pocketmine\network\mcpe\serializer\ChunkSerializer;
use pocketmine\scheduler\AsyncTask;
use pocketmine\utils\Binary;
use pocketmine\world\format\Chunk;
use pocketmine\world\format\io\FastChunkSerializer;
/** @phpstan-ignore-next-line */
use function xxhash64;

class ChunkRequestTask extends AsyncTask{
	private const TLS_KEY_PROMISE = "promise";
	private const TLS_KEY_ERROR_HOOK = "errorHook";

	/** @var string */
	protected $chunk;
	/** @var int */
	protected $chunkX;
	/** @var int */
	protected $chunkZ;
	/** @phpstan-var DimensionIds::* */
	private int $dimensionId;

	/** @var Compressor */
	protected $compressor;
	/** @var int */
	protected $mappingProtocol;

	private string $tiles;

	/**
	 * @phpstan-param (\Closure() : void)|null $onError
	 */
	public function __construct(int $chunkX, int $chunkZ, int $dimensionId, Chunk $chunk, int $mappingProtocol, CachedChunkPromise $promise, Compressor $compressor, ?\Closure $onError = null){
		$this->compressor = $compressor;
		$this->mappingProtocol = $mappingProtocol;

		$this->chunk = FastChunkSerializer::serializeTerrain($chunk);
		$this->chunkX = $chunkX;
		$this->chunkZ = $chunkZ;
		/** @phpstan-ignore-next-line */
		$this->dimensionId = $dimensionId;
		$this->tiles = ChunkSerializer::serializeTiles($chunk, $mappingProtocol);

		$this->storeLocal(self::TLS_KEY_PROMISE, $promise);
		$this->storeLocal(self::TLS_KEY_ERROR_HOOK, $onError);
	}

	public function onRun() : void{
		$chunk = FastChunkSerializer::deserializeTerrain($this->chunk);

		$cache = new CachedChunk();

		$dimensionId = $this->dimensionId;

		$blockMapper = RuntimeBlockMapping::getInstance();

		foreach(ChunkSerializer::serializeSubChunks($chunk, $dimensionId, $blockMapper, $this->mappingProtocol) as $subChunk){
			/** @phpstan-ignore-next-line */
			$cache->addSubChunk(Binary::readLong(xxhash64($subChunk)), $subChunk);
		}

		$encoder = PacketSerializer::encoder($this->mappingProtocol);
		$biomeEncoder = clone $encoder;
		ChunkSerializer::serializeBiomes($chunk, $biomeEncoder);
		/** @phpstan-ignore-next-line */
		$cache->setBiomes(Binary::readLong(xxhash64($chunkBuffer = $biomeEncoder->getBuffer())), $chunkBuffer);

		$chunkDataEncoder = clone $encoder;
		ChunkSerializer::serializeChunkData($chunk, $chunkDataEncoder, $this->tiles);

		$cache->compressPackets(
			$this->chunkX,
			$this->chunkZ,
			$dimensionId,
			$chunkDataEncoder->getBuffer(),
			$this->compressor,
			$this->mappingProtocol
		);

		$this->setResult($cache);
	}

	public function onError() : void{
		/**
		 * @var \Closure|null $hook
		 * @phpstan-var (\Closure() : void)|null $hook
		 */
		$hook = $this->fetchLocal(self::TLS_KEY_ERROR_HOOK);
		if($hook !== null){
			$hook();
		}
	}

	public function onCompletion() : void{
		/** @var CachedChunk $result */
		$result = $this->getResult();

		/** @var CachedChunkPromise $promise */
		$promise = $this->fetchLocal(self::TLS_KEY_PROMISE);
		$promise->resolve($result);
	}
}
