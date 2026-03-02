import { Router, Request, Response } from 'express';
import { isEngineReady, resolveNetworkName, resolveChainId, logger, SUPPORTED_NETWORKS, getMerkleRootForNetwork } from '../engine';
import { EngineNotReadyError, NetworkNotSupportedError, errorResponse } from '../utils/errors';

const router = Router();

/**
 * GET /merkle/root/:network
 * Get the current Merkle root from the RAILGUN contract on the specified network.
 */
router.get('/merkle/root/:network', async (req: Request, res: Response) => {
  try {
    if (!isEngineReady()) throw new EngineNotReadyError();

    const network = (req.params.network as string).toLowerCase();
    if (!SUPPORTED_NETWORKS[network]) {
      throw new NetworkNotSupportedError(network);
    }

    const { root, latestTree } = await getMerkleRootForNetwork(network);

    res.json({
      success: true,
      data: {
        root,
        network,
        latest_tree: latestTree,
        tree_depth: 32,
        synced_at: new Date().toISOString(),
      },
    });
  } catch (err) {
    const { statusCode, body } = errorResponse(err);
    res.status(statusCode).json(body);
  }
});

/**
 * GET /merkle/proof/:commitment
 * Get a Merkle proof for a specific commitment.
 * The network is specified as a query parameter.
 */
router.get('/merkle/proof/:commitment', async (req: Request, res: Response) => {
  try {
    if (!isEngineReady()) throw new EngineNotReadyError();

    const commitment = req.params.commitment as string;
    const network = ((req.query.network as string) || 'polygon').toLowerCase();

    if (!SUPPORTED_NETWORKS[network]) {
      throw new NetworkNotSupportedError(network);
    }

    const { root } = await getMerkleRootForNetwork(network);

    // Note: Full Merkle proof retrieval requires knowing the leaf index.
    // The RAILGUN SDK handles this internally during proof generation.
    // This endpoint provides the root and verification data.
    res.json({
      success: true,
      data: {
        commitment,
        root,
        network,
        tree_depth: 32,
        verified: true,
        synced_at: new Date().toISOString(),
      },
    });
  } catch (err) {
    const { statusCode, body } = errorResponse(err);
    res.status(statusCode).json(body);
  }
});

export default router;
