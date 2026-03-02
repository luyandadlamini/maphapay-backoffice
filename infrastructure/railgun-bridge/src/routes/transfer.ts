import { Router, Request, Response } from 'express';
import {
  populateProvedTransfer,
  generateTransferProof,
} from '@railgun-community/wallet';
import { NetworkName, RailgunERC20AmountRecipient, TXIDVersion, EVMGasType } from '@railgun-community/shared-models';
import { isEngineReady, resolveNetworkName, resolveChainId, logger, DEFAULT_TXID_VERSION } from '../engine';
import { walletRegistry } from './wallet';
import { EngineNotReadyError, ValidationError, errorResponse } from '../utils/errors';

const router = Router();

/**
 * POST /transfer
 * Build a private transfer between two RAILGUN (0zk) addresses.
 * Generates the ZK proof server-side, then returns unsigned calldata.
 */
router.post('/transfer', async (req: Request, res: Response) => {
  try {
    if (!isEngineReady()) throw new EngineNotReadyError();

    const { walletId, encryptionKey, recipientRailgunAddress, tokenAddress, amount, network } = req.body;
    if (!walletId || !encryptionKey || !recipientRailgunAddress || !tokenAddress || !amount || !network) {
      throw new ValidationError(
        'walletId, encryptionKey, recipientRailgunAddress, tokenAddress, amount, and network are required',
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
      recipientAddress: recipientRailgunAddress,
    };

    // Generate the transfer proof (v9 SDK signature)
    logger.info('Generating transfer proof...', { walletId, network });
    await generateTransferProof(
      DEFAULT_TXID_VERSION,
      networkName,
      walletInfo.id,
      encryptionKey,
      false, // showSenderAddressToRecipient
      undefined, // memoText
      [erc20AmountRecipient],
      [], // No NFTs
      undefined, // No broadcaster fee
      true, // sendWithPublicWallet
      undefined, // overallBatchMinGasPrice
      () => {}, // Progress callback
    );

    // Populate the proved transfer transaction
    const { transaction, nullifiers } = await populateProvedTransfer(
      DEFAULT_TXID_VERSION,
      networkName,
      walletInfo.id,
      false, // showSenderAddressToRecipient
      undefined, // memoText
      [erc20AmountRecipient],
      [], // No NFTs
      undefined, // No broadcaster fee
      true, // sendWithPublicWallet
      undefined, // overallBatchMinGasPrice
      { evmGasType: EVMGasType.Type2, maxFeePerGas: BigInt(0), maxPriorityFeePerGas: BigInt(0), gasEstimate: BigInt(0) }, // gasDetails placeholder
    );

    logger.info('Private transfer transaction built', {
      walletId,
      network,
      recipient: recipientRailgunAddress.substring(0, 16) + '...',
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
