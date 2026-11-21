-- Dynabase AI: extend approved draft types (no existing records removed).
-- Apply once before deploying the updated backend/front-end.
-- Existing create_client / create_tender values remain valid.
ALTER TABLE `dynabase_ai_chat_messages`
  MODIFY COLUMN `action_type` ENUM('create_client','create_tender','create_keyperson','create_gift_list') DEFAULT NULL;
