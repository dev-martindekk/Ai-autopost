-- Fix member_plans.status ENUM — add 'cancelled' value
-- Production was missing 'cancelled' causing activateOrExtend() to silently fail
ALTER TABLE member_plans
  MODIFY COLUMN status ENUM('active','expired','suspended','cancelled') DEFAULT 'active';
