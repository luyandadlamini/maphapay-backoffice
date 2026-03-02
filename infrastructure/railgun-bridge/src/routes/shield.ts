import { Router, Request, Response } from 'express';
import {
  populateShield,
  gasEstimateForShield,
} from '@railgun-community/wallet';
import { NetworkName, RailgunERC20AmountRecipient, TXIDVersion } from '@railgun-community/shared-models';
import { isEngineReady, resolveNetworkName, resolveChainId, logger, DEFAULT_TXID_VERSION } from '../engine';
import { walletRegistry } from './wallet';
import { EngineNotReadyError, ValidationError, errorResponse } from '../utils/errors';

const router = Router();

/**
 * POST /shield
 * Build a shield (deposit) transaction for the RAILGUN privacy pool.
 * Returns unsigned calldata that the frontend/relayer submits on-chain.
 */
router.post('/shield', async (req: Request, res: Response) => {
  try {
    if (!isEngineReady()) throw new EngineNotReadyError();

    const { walletId, tokenAddress, amount, network, shieldPrivateKey } = req.body;
    if (!walletId || !tokenAddress || !amount || !network || !shieldPrivateKey) {
      throw new ValidationError('walletId, tokenAddress, amount, network, and shieldPrivateKey are required');
    }

    const walletInfo = walletRegistry.get(walletId);
    if (!walletInfo) {
      res.status(404).json({
        success: false,
        error: { code: 'WALLET_NOT_FOUND', message: `Wallet ${walletId} not found` },
      });
      return;
    }

    const networkName = resolveNetworkName(network);

    // Build ERC20 amount recipient (v9 SDK uses AmountRecipient)
    const shieldAmountRecipient: RailgunERC20AmountRecipient = {
      tokenAddress,
      amount: BigInt(amount),
      recipientAddress: walletInfo.railgunAddress,
    };

    // Populate shield transaction
    const { transaction, nullifiers } = await populateShield(
      DEFAULT_TXID_VERSION,
      networkName,
      shieldPrivateKey,
      [shieldAmountRecipient],
      [], // No NFTs
    );

    // Estimate gas
    let gasEstimate: string | undefined;
    try {
      const estimate = await gasEstimateForShield(
        DEFAULT_TXID_VERSION,
        networkName,
        shieldPrivateKey,
        [shieldAmountRecipient],
        [], // No NFTs
        walletInfo.railgunAddress, // fromWalletAddress
      );
      gasEstimate = estimate.gasEstimate.toString();
    } catch (gasErr) {
      logger.warn('Gas estimation failed for shield', {
        error: gasErr instanceof Error ? gasErr.message : String(gasErr),
      });
    }

    logger.info('Shield transaction built', {
      walletId,
      network,
      tokenAddress,
      amount,
    });

    res.json({
      success: true,
      data: {
        transaction: {
          to: transaction.to,
          data: transaction.data,
          value: transaction.value?.toString() || '0',
        },
        gas_estimate: gasEstimate,
        nullifiers,
        network,
      },
    });
  } catch (err) {
    const { statusCode, body } = errorResponse(err);
    res.status(statusCode).json(body);
  }
});

export default router;
