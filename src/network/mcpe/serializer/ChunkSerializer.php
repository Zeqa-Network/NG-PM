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

namespace pocketmine\network\mcpe\serializer;

use pocketmine\block\tile\Spawnable;
use pocketmine\data\bedrock\BiomeIds;
use pocketmine\data\bedrock\LegacyBiomeIdToStringIdMap;
use pocketmine\nbt\TreeRoot;
use pocketmine\network\mcpe\convert\RuntimeBlockMapping;
use pocketmine\network\mcpe\protocol\serializer\NetworkNbtSerializer;
use pocketmine\network\mcpe\protocol\serializer\PacketSerializer;
use pocketmine\network\mcpe\protocol\serializer\PacketSerializerContext;
use pocketmine\network\mcpe\protocol\types\DimensionIds;
use pocketmine\utils\Binary;
use pocketmine\utils\BinaryStream;
use pocketmine\world\format\Chunk;
use pocketmine\world\format\PalettedBlockArray;
use pocketmine\world\format\SubChunk;
use function chr;
use function count;
use function str_repeat;

final class ChunkSerializer{
	public const LOWER_PADDING_SIZE = 4;

	private function __construct(){
		//NOOP
	}

		/**
	 * Returns the min/max subchunk index expected in the protocol.
	 * This has no relation to the world height supported by PM.
	 *
	 * @phpstan-param DimensionIds::* $dimensionId
	 * @return int[]
	 * @phpstan-return array{int, int}
	 */
	public static function getDimensionChunkBounds(int $dimensionId) : array{
		return match($dimensionId){
			DimensionIds::OVERWORLD => [-4, 19],
			DimensionIds::NETHER => [0, 7],
			DimensionIds::THE_END => [0, 15],
			default => throw new \InvalidArgumentException("Unknown dimension ID $dimensionId"),
		};
	}

	/**
	 * Returns the number of subchunks that will be sent from the given chunk.
	 * Chunks are sent in a stack, so every chunk below the top non-empty one must be sent.
	 */
	public static function getSubChunkCount(Chunk $chunk, int $dimensionId) : int{
		//if the protocol world bounds ever exceed the PM supported bounds again in the future, we might need to
		//polyfill some stuff here
		[$minSubChunkIndex, $maxSubChunkIndex] = self::getDimensionChunkBounds($dimensionId);
		for($y = $maxSubChunkIndex, $count = $maxSubChunkIndex - $minSubChunkIndex + 1; $y >= $minSubChunkIndex; --$y, --$count){
			if($chunk->getSubChunk($y)->isEmptyFast()){
				continue;
			}
			return $count;
		}

		return 0;
	}

	/**
	 * @return string[]
	 */
	public static function serializeSubChunks(Chunk $chunk, int $dimensionId, RuntimeBlockMapping $blockMapper, PacketSerializerContext $encoderContext) : array
	{
		$stream = PacketSerializer::encoder($encoderContext);

		$emptyChunkStream = clone $stream;
		$emptyChunkStream->putByte(8); //subchunk version 8
		$emptyChunkStream->putByte(0); //0 layers - client will treat this as all-air

		$subChunks = [];

		$writtenCount = 0;
		$subChunkCount = self::getSubChunkCount($chunk, $dimensionId);
		[$minSubChunkIndex, $maxSubChunkIndex] = self::getDimensionChunkBounds($dimensionId);
		for($y = $minSubChunkIndex; $writtenCount < $subChunkCount; ++$y, ++$writtenCount){
			$subChunkStream = clone $stream;
			self::serializeSubChunk($chunk->getSubChunk($y), $blockMapper, $subChunkStream, false);
			$subChunks[] = $subChunkStream->getBuffer();
		}

		return $subChunks;
	}

	public static function serializeFullChunk(Chunk $chunk, int $dimensionId, RuntimeBlockMapping $blockMapper, PacketSerializerContext $encoderContext, ?string $tiles = null) : string{
		$stream = PacketSerializer::encoder($encoderContext);

		foreach(self::serializeSubChunks($chunk, $dimensionId, $blockMapper, $encoderContext) as $subChunk){
			$stream->put($subChunk);
		}

		self::serializeBiomes($chunk, $stream);
		self::serializeChunkData($chunk, $stream, $tiles);

		return $stream->getBuffer();
	}

	public static function serializeBiomes(Chunk $chunk, PacketSerializer $stream) : void{
		//TODO: right now we don't support 3D natively, so we just 3Dify our 2D biomes so they fill the column
		$encodedBiomePalette = self::serializeBiomesAsPalette($chunk);
		$stream->put(str_repeat($encodedBiomePalette, 24));
	}

	public static function serializeBorderBlocks(PacketSerializer $stream) : void {
		$stream->putByte(0); //border block array count
		//Border block entry format: 1 byte (4 bits X, 4 bits Z). These are however useless since they crash the regular client.
	}

	public static function serializeChunkData(Chunk $chunk, PacketSerializer $stream, ?string $tiles = null) : void{
		self::serializeBorderBlocks($stream);

		if($tiles !== null){
			$stream->put($tiles);
		}else{
			$stream->put(self::serializeTiles($chunk, $stream->getProtocolId()));
		}
	}

	public static function serializeSubChunk(SubChunk $subChunk, RuntimeBlockMapping $blockMapper, PacketSerializer $stream, bool $persistentBlockStates) : void{
		$layers = $subChunk->getBlockLayers();
		$stream->putByte(8); //version

		$stream->putByte(count($layers));

		foreach($layers as $blocks){
			$bitsPerBlock = $blocks->getBitsPerBlock();
			$words = $blocks->getWordArray();
			$stream->putByte(($bitsPerBlock << 1) | ($persistentBlockStates ? 0 : 1));
			$stream->put($words);
			$palette = $blocks->getPalette();

			if($bitsPerBlock !== 0){
				//these LSHIFT by 1 uvarints are optimizations: the client expects zigzag varints here
				//but since we know they are always unsigned, we can avoid the extra fcall overhead of
				//zigzag and just shift directly.
				$stream->putUnsignedVarInt(count($palette) << 1); //yes, this is intentionally zigzag
			}
			if($persistentBlockStates){
				$nbtSerializer = new NetworkNbtSerializer();
				foreach($palette as $p){
					$stream->put($nbtSerializer->write(new TreeRoot($blockMapper->getBedrockKnownStates()[$blockMapper->toRuntimeId($p, $stream->getProtocolId())])));
				}
			}else{
				foreach($palette as $p){
					$stream->put(Binary::writeUnsignedVarInt($blockMapper->toRuntimeId($p, $stream->getProtocolId()) << 1));
				}
			}
		}
	}

	public static function serializeTiles(Chunk $chunk, int $mappingProtocol) : string{
		$stream = new BinaryStream();
		foreach($chunk->getTiles() as $tile){
			if($tile instanceof Spawnable){
				$stream->put($tile->getSerializedSpawnCompound($mappingProtocol)->getEncodedNbt());
			}
		}

		return $stream->getBuffer();
	}

	private static function serializeBiomesAsPalette(Chunk $chunk) : string{
		$biomeIdMap = LegacyBiomeIdToStringIdMap::getInstance();
		$biomePalette = new PalettedBlockArray($chunk->getBiomeId(0, 0));
		for($x = 0; $x < 16; ++$x){
			for($z = 0; $z < 16; ++$z){
				$biomeId = $chunk->getBiomeId($x, $z);
				if($biomeIdMap->legacyToString($biomeId) === null){
					//make sure we aren't sending bogus biomes - the 1.18.0 client crashes if we do this
					$biomeId = BiomeIds::OCEAN;
				}
				for($y = 0; $y < 16; ++$y){
					$biomePalette->set($x, $y, $z, $biomeId);
				}
			}
		}

		$biomePaletteBitsPerBlock = $biomePalette->getBitsPerBlock();
		$encodedBiomePalette =
			chr(($biomePaletteBitsPerBlock << 1) | 1) . //the last bit is non-persistence (like for blocks), though it has no effect on biomes since they always use integer IDs
			$biomePalette->getWordArray();

		//these LSHIFT by 1 uvarints are optimizations: the client expects zigzag varints here
		//but since we know they are always unsigned, we can avoid the extra fcall overhead of
		//zigzag and just shift directly.
		$biomePaletteArray = $biomePalette->getPalette();
		if($biomePaletteBitsPerBlock !== 0){
			$encodedBiomePalette .= Binary::writeUnsignedVarInt(count($biomePaletteArray) << 1);
		}
		foreach($biomePaletteArray as $p){
			$encodedBiomePalette .= Binary::writeUnsignedVarInt($p << 1);
		}

		return $encodedBiomePalette;
	}
}
