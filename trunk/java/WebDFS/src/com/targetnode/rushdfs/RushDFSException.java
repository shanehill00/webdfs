package com.targetnode.rushdfs;

/**
 *
 * @author shane
 */
public class RushDFSException extends Exception{

    private static final long serialVersionUID = 42L;

    public RushDFSException( Exception e ){
       super(e);
    }

    public RushDFSException( String s ){
       super(s);
    }

}
