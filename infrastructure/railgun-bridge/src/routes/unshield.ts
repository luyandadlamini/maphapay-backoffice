import { Router, Request, Response } from 'express';
import {
  populateProvedUnshield,
  generateUnshieldProof,
} from '@railgun-community/wallet';
import { NetworkName, RailgunERC20AmountRecipient, TXIDVersion, EVMGasType } from '@railgun-community/shared-models';
import { isEngineReady, resolveNetworkName, resolveChainId, logger, DEFAULT_TXID_VERSION } from '../engine';
import { walletRegistry } from './wallet';
import { EngineNotReadyError, ValidationError, errorResponse } from '../utils/errors';

const router = Router();

/**
 * POST /unshield
 * Build an unshield (withdraw) transaction from the RAILGUN privacy pool.
 * Generates the ZK proof server-side, then returns unsigned calldata.
 */
router.post('/unshield', async (req: Request, res: Response) => {
  try {
    if (!isEngineReady()) throw new EngineNotReadyError();

    const { walletId, encryptionKey, recipientAddress, tokenAddress, amount, network } = req.body;
    if (!walletId || !encryptionKey || !recipientAddress || !tokenAddress || !amount || !network) {
      throw new ValidationError(
        'walletId, encryptionKey, recipientAddress, tokenAddress, amount, and network are required',
      );
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

    const erc20AmountRecipient: RailgunERC20AmountRecipient = {
      tokenAddress,
      amount: BigInt(amount),
      recipientAddress,
    };

    // Generate the unshield proof (v9 SDK signature)
    logger.info('Generating unshield proof...', { walletId, network });
    await generateUnshieldProof(
      DEFAULT_TXID_VERSION,
      networkName,
      walletInfo.id,
      encryptionKey,
      [erc20AmountRecipient],
      [], // No NFTs
      undefined, // No broadcaster fee
      true, // sendWithPublicWallet
      undefined, // overallBatchMinGasPrice
      () => {}, // Progress callback
    );

    // Populate the proved unshield transaction
    const { transaction, nullifiers } = await populateProvedUnshield(
      DEFAULT_TXID_VERSION,
      networkName,
      walletInfo.id,
      [erc20AmountRecipient],
      [], // No NFTs
      undefined, // No broadcaster fee
      true, // sendWithPublicWallet
      undefined, // overallBatchMinGasPrice
      { evmGasType: EVMGasType.Type2, maxFeePerGas: BigInt(0), maxPriorityFeePerGas: BigInt(0), gasEstimate: BigInt(0) }, // gasDetails placeholder — caller sets actual gas
    );

    logger.info('Unshield transaction built', {
      walletId,
      network,
      recipient: recipientAddress,
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
