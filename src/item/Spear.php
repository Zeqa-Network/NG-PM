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

namespace pocketmine\item;

use pocketmine\block\BlockToolType;
use pocketmine\entity\Living;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\item\enchantment\VanillaEnchantments;
use pocketmine\player\Player;
use pocketmine\world\sound\SpearAttackHitSound;
use pocketmine\world\sound\SpearAttackMissSound;
use pocketmine\world\sound\SpearLungeSound;

class Spear extends TieredTool implements Releasable {

	const MINIMUM_SPEED = 0.13;
	const MINIMUM_DISTANCE = 2.5;
	const MAXIMUM_DISTANCE = 5;

	public function getBlockToolType(): int {
		return BlockToolType::SPEAR;
	}

	public function onUsingTick(Player $player, int $ticksUsed): void {
		if ($ticksUsed % 4 !== 0 || $ticksUsed <= 7) {
			return;
		}
		$this->handleChargeAttack($player);
	}

	private function handleChargeAttack(Player $player): void {
		$movementSpeed = $player->getMovementSpeed();
		$direction = $player->getDirectionVector()->normalize()->multiply(1.5);
		$boundingBox = $player->getBoundingBox()->expandedCopy(1.5, 1.0, 1.5)->offset($direction->x, $direction->y, $direction->z);
		$baseDamage = $this->getAttackPoints() * 1.5;

		foreach ($player->getWorld()->getNearbyEntities($boundingBox, $player) as $entity) {
			if (!$entity instanceof Living || !$entity->isAlive() || $entity->getId() === $player->getId()) {
				continue;
			}
			if ($entity instanceof Player) {
				$toAttacker = $player->getPosition()->subtractVector($entity->getPosition())->normalize();
				$movementSpeed += max(0, $entity->getMotion()->dot($toAttacker));
			}
			$playerPos = $player->getPosition();
			$entityPos = $entity->getPosition();
			if ($playerPos->distance($entityPos) < self::MINIMUM_DISTANCE || $playerPos->distance($entityPos) > self::MAXIMUM_DISTANCE) {
				return;
			}
			$this->handleDamage($player, $entity, $baseDamage + ($movementSpeed * 3.0));
		}
	}

	public function handleJabAttack(Player $player, float $movementSpeed): void {
		$hasItemCooldown = $player->hasItemCooldown($this);
		if (!$hasItemCooldown) {
			$this->handleLunge($player);
			$player->resetItemCooldown($this, $this->getTierCooldown());
		} else {
			return;
		}
		if ($movementSpeed > self::MINIMUM_SPEED * 0.5 || $player->isSprinting()) {
			$eyePos = $player->getEyePos();
			$facingDirection = $player->getDirectionVector()->normalize();

			$maxDistance = self::MAXIMUM_DISTANCE;
			$minDistance = self::MINIMUM_DISTANCE;
			$boundingBox = $player->getBoundingBox()->expandedCopy($maxDistance * 1.7, $maxDistance * 1.7, $maxDistance * 2.5);

			$highestScore = -1.0;
			$closestTarget = null;

			foreach ($player->getWorld()->getNearbyEntities($boundingBox, $player) as $nearbyEntity) {
				if (!$nearbyEntity instanceof Living || !$nearbyEntity->isAlive() || $nearbyEntity->getId() === $player->getId()) {
					continue;
				}
				$entityBody = $nearbyEntity->getPosition()->add(0, $nearbyEntity->getEyeHeight() / 2, 0);
				$distanceTo = $eyePos->distance($entityBody);
				$facingDot = $facingDirection->dot($entityBody->subtractVector($eyePos)->normalize());

				if ($distanceTo > $maxDistance || $distanceTo < $minDistance || $facingDot < 0.866 - 0.35) {
					continue;
				}
				$targetScore = $facingDot - ($distanceTo / $maxDistance) * 0.05;
				if ($targetScore > $highestScore) {
					$highestScore = $targetScore;
					$closestTarget = $nearbyEntity;
				}
			}
			if ($closestTarget !== null) {
				$this->handleDamage($player, $closestTarget, $this->getJabDamage());
			} else {
				$player->getWorld()->addSound($player->getPosition(), new SpearAttackMissSound($this->getTier()));
			}
		} else {
			$player->getWorld()->addSound($player->getPosition(), new SpearAttackMissSound($this->getTier()));
		}
	}

	private function handleDamage(Player $player, Living $target, float $damage): void {
		$event = new EntityDamageByEntityEvent($player, $target, EntityDamageEvent::CAUSE_ENTITY_ATTACK, $damage + $this->getTierAttackDamage());
		$target->attack($event);
		if (!$event->isCancelled()) {
			$player->getWorld()->addSound($player->getPosition(), new SpearAttackHitSound($this->getTier()));
			$this->applyDamage(1);
		}
	}

	public function handleLunge(Player $player): void {
		$lungeLevel = $this->getEnchantmentLevel(VanillaEnchantments::LUNGE());
		if ($lungeLevel > 0) {
			$dir = $player->getDirectionVector()->multiply(0.8 + ($lungeLevel * 0.4));
			$player->setMotion($player->getMotion()->addVector($dir));
			$player->getWorld()->addSound($player->getPosition(), new SpearLungeSound($lungeLevel));
			$hunger = $player->getHungerManager();
			$hunger->setFood(max(0, $hunger->getFood() - $lungeLevel));
		}
	}

	public function getJabDamage(): float {
		$damage = (float)$this->getAttackPoints();

		$lungeLevel = $this->getEnchantmentLevel(VanillaEnchantments::LUNGE());
		$damage += $lungeLevel * 1.5;

		return $damage;
	}

	public function getTierCooldown(): int {
		return match ($this->getTier()) {
			ToolTier::WOOD => 13,
			ToolTier::STONE => 15,
			ToolTier::COPPER => 17,
			ToolTier::IRON, ToolTier::GOLD => 19,
			ToolTier::DIAMOND => 21,
			ToolTier::NETHERITE => 23,
		};
	}

	public function getTierAttackDamage(): int {
		return match ($this->getTier()) {
			ToolTier::WOOD, ToolTier::GOLD => 2,
			ToolTier::STONE, ToolTier::COPPER => 3,
			ToolTier::IRON, => 4,
			ToolTier::DIAMOND => 5,
			ToolTier::NETHERITE => 6,
		};
	}

	public function canStartUsingItem(Player $player): bool {
		if ($player->hasItemCooldown($this)) {
			return false;
		}
		return true;
	}

	public function getCooldownTag(): ?string {
		return ItemCooldownTags::SPEAR;
	}
}
