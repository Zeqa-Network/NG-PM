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
use pocketmine\block\Liquid;
use pocketmine\entity\Entity;
use pocketmine\entity\Living;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\item\enchantment\VanillaEnchantments;
use pocketmine\player\Player;
use pocketmine\world\sound\SpearAttackHitSound;
use pocketmine\world\sound\SpearAttackMissSound;
use pocketmine\world\sound\SpearLungeSound;

class Spear extends TieredTool implements Releasable {

	const MINIMUM_VELOCITY_DAMAGE = 4.6;
	const MINIMUM_VELOCITY_KNOCKBACK = 5.1;
	const MINIMUM_DISTANCE = 2;
	const MAXIMUM_DISTANCE = 5;
	const MAX_HOLD_DURATION = 9;
	const MINIMUM_FOOD = 6;

	const STAGE_NONE = 0;
	const STAGE_ENGAGED = 1;
	const STAGE_TIRED = 2;
	const STAGE_DISENGAGED = 3;

	const STAGE_TIRED_DELAY = 3;
	const STAGE_DISENGAGED_DELAY = 4;

	protected int $stage;

	public function __construct(ItemIdentifier $identifier, string $name, ToolTier $tier, array $enchantmentTags = []) {
		$this->stage = self::STAGE_NONE;
		parent::__construct($identifier, $name, $tier, $enchantmentTags);
	}

	public function getBlockToolType(): int {
		return BlockToolType::SPEAR;
	}

	public function onUsingTick(Player $player, int $ticksUsed): void {
		$secondsUsed = ($ticksUsed / 20);
		$activationDelay = self::getTierActivationDelay();

		if ($secondsUsed >= self::MAX_HOLD_DURATION) {
			$this->stage = self::STAGE_NONE;
			$player->setUsingItem(false);
			return;
		}
		if ($secondsUsed >= self::STAGE_DISENGAGED_DELAY) {
			$this->stage = self::STAGE_DISENGAGED;
		} elseif ($secondsUsed >= self::STAGE_TIRED_DELAY) {
			$this->stage = self::STAGE_TIRED;
		} elseif ($secondsUsed >= $activationDelay) {
			$this->stage = self::STAGE_ENGAGED;
		}

		if ($this->stage >= self::STAGE_ENGAGED) {
			$this->handleChargeAttack($player);
		}
	}

	private function handleChargeAttack(Player $player): void {
		$direction = $player->getDirectionVector()->normalize()->multiply(1.5);
		$boundingBox = $player->getBoundingBox()->expandedCopy(1.5, 1.0, 1.5)->offset($direction->x, $direction->y, $direction->z);

		foreach ($player->getWorld()->getNearbyEntities($boundingBox, $player) as $entity) {
			if (!$entity instanceof Living || !$entity->isAlive() || $entity->getId() === $player->getId()) {
				continue;
			}
			$relativeVelocity = abs($player->getCurrentVelocity() - ($entity instanceof Player ? $entity->getCurrentVelocity() : 0.0));

			if ($relativeVelocity >= self::MINIMUM_VELOCITY_DAMAGE) {
				$distance = $player->getPosition()->distance($entity->getPosition());
				if ($distance < self::MINIMUM_DISTANCE || $distance > self::MAXIMUM_DISTANCE) {
					continue;
				}
				$this->handleDamage($player, $entity, $this->getChargeDamage($player, $entity));
			}
		}
	}

	public function handleJabAttack(Player $player): void {
		$hasItemCooldown = $player->hasItemCooldown($this);
		if (!$hasItemCooldown) {
			$this->handleLunge($player);
			$player->resetItemCooldown($this, $this->getTierCooldown());
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
				return;
			}
			$player->getWorld()->addSound($player->getPosition(), new SpearAttackMissSound($this->getTier()));
		}
	}

	private function handleDamage(Player $player, Living $target, float $damage): void {
		$noKnockback = $this->stage === self::STAGE_DISENGAGED || ($player->isUsingItem() && $player->getCurrentVelocity() < self::MINIMUM_VELOCITY_KNOCKBACK);
		$damageEvent = new EntityDamageByEntityEvent($player, $target, EntityDamageEvent::CAUSE_ENTITY_ATTACK, $damage + $this->getAttackPoints());
		if($noKnockback) {
			$damageEvent->setKnockBack(0);
		}
		$target->attack($damageEvent);
		$this->applyDamage(1);

		if (!$damageEvent->isCancelled()) {
			$player->getWorld()->addSound($player->getPosition(), new SpearAttackHitSound($this->getTier()));
		}
	}

	public function handleLunge(Player $player): void {
		if ($this->canLunge($player)) {
			$lungeLevel = $this->getEnchantmentLevel(VanillaEnchantments::LUNGE());
			$directionVector = $player->getDirectionVector()->multiply(0.8 + ($lungeLevel * 0.4));
			$directionVector->y = 0;

			$player->setMotion($player->getMotion()->addVector($directionVector));
			$player->getWorld()->addSound($player->getPosition(), new SpearLungeSound($lungeLevel));
			$player->getHungerManager()->exhaust($lungeLevel + 2);

			$newItem = clone $this;
			$newItem->applyDamage(1);
			$player->getInventory()->setItemInHand($newItem);
		}
	}

	public function canLunge(Player $player): bool {
		$playerPos = $player->getPosition();
		$blockAtPos = $player->getWorld()->getBlockAt($playerPos->getFloorX(), $playerPos->getFloorY(), $playerPos->getFloorZ());

		if ($this->getEnchantmentLevel(VanillaEnchantments::LUNGE()) <= 0) {
			return false;
		}
		if ($player->isGliding() || $player->isSwimming()) {
			return false;
		}
		if ($blockAtPos instanceof Liquid || $player->isUnderwater()) {
			return false;
		}
		if (!$player->isCreative() && $player->getHungerManager()->getFood() < self::MINIMUM_FOOD) {
			return false;
		}

		return true;
	}

	public function getChargeDamage(Player $player, Entity $entity): float {
		$tierMultiplier = match ($this->getTier()) {
			ToolTier::WOOD, ToolTier::GOLD => 0.7,
			ToolTier::STONE, ToolTier::COPPER => 0.82,
			ToolTier::IRON => 0.95,
			ToolTier::DIAMOND => 1.075,
			ToolTier::NETHERITE => 1.2,
		};

		$sharpnessEnchant = $this->getEnchantment(VanillaEnchantments::SHARPNESS())?->getLevel() ?? 0;
		$sharpnessBonus = (($sharpnessEnchant <=> 0) + $sharpnessEnchant) / 2;

		$playerSpeed = $player->getCurrentVelocity();
		$entitySpeed = $entity instanceof Player ? $entity->getCurrentVelocity() : 0.0;

		$relativeSpeed = abs($playerSpeed - $entitySpeed);

		return ($relativeSpeed * $tierMultiplier) + $sharpnessBonus;
	}

	public function getJabDamage(): float {
		$lungeLevel = $this->getEnchantmentLevel(VanillaEnchantments::LUNGE());
		return (float)$this->getAttackPoints() + $lungeLevel * 1.5;
	}

	public function getAttackPoints(): int {
		return max(2, $this->getTier()->getHarvestLevel());
	}

	public function getTierCooldown(): int {
		return 11 + ($this->getTier()->getHarvestLevel() * 2);
	}

	public function getTierActivationDelay(): float {
		$level = $this->getTier()->getHarvestLevel();
		return $level <= 4 ? 0.80 - ($level * 0.05) : 0.60 - (($level - 4) * 0.10);
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
